<?php

use App\Enums\DeliveryMode;
use App\Enums\PaymentState;
use App\Enums\RevenueRole;
use App\Models\DeliveryIntegration;
use App\Models\FeeDistribution;
use App\Models\PlatformRoleHolder;
use App\Models\User;
use App\Services\CartManager;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryQuoteTestHelpers.php';

/*
 * The Uber twin of DoorDashDeliveryMoneyTest: with Uber now centrally billed
 * (umbrella sub-orgs under Plateful's root account), the same gross-up and
 * courier/margin accounting must hold — pinned here independently so an Uber
 * regression cannot hide behind the DoorDash suite.
 */

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.stripe_variable_rate' => 0.029]);
    config(['services.uber_direct.client_id' => 'cid_platform']);
    config(['services.uber_direct.client_secret' => 'csec_platform']);
    Mail::fake();

    $f = cartFixture();
    $this->fixture = $f;
    $this->restaurant = $f['restaurant'];
    $this->restaurant->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['uber'],
        'tax_rate_percent' => 0,
        'prep_time_minutes' => 10,
        'phone' => '5551234567',
        'application_fee_percent' => 4,
    ]);

    DeliveryIntegration::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'customer_id' => 'org_uber_money',
    ]);
});

function uberMoneyHost(): string
{
    return 'http://'.test()->restaurant->subdomain.'.plateful.test';
}

function uberMoneyCartCookie(): string
{
    $f = test()->fixture;

    $response = test()->post(uberMoneyHost()."/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    return cartCookieFrom($response);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function uberMoneyCheckoutBody(string $token, array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Bob',
        'customer_email' => 'bob@example.test',
        'customer_phone' => '+15555550100',
        'type' => 'delivery',
        'delivery_address' => quoteAddress(),
        'delivery_quote_token' => $token,
        'tip_preset' => 'custom',
        'tip_custom_cents' => 500,
    ], $overrides);
}

it('quotes a grossed-up delivery fee for an Uber restaurant', function () {
    // Uber returns the raw courier fee; the customer sees it grossed up so
    // the restaurant bears no Stripe fee on the delivery line.
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(['access_token' => 't', 'expires_in' => 2592000]),
        'api.uber.com/*' => Http::response([
            'id' => 'dqt_money_1',
            'fee' => 900,
            'duration' => 30,
            'expires' => now()->addMinutes(15)->toIso8601String(),
        ]),
    ]);

    $response = $this->withCookie(CartManager::COOKIE_NAME, uberMoneyCartCookie())
        ->postJson(uberMoneyHost().'/checkout/delivery-quote', ['address' => quoteAddress()]);

    $response->assertOk();
    // round(900 × 1.04 / 0.971) = 964
    expect($response->json('quote.feeCents'))->toBe(964);
    // Central billing can re-quote, so the price is customer-visible → countdown.
    expect($response->json('quote.expiresAt'))->not->toBeNull();
});

it('recovers courier + margin + tip through the application fee', function () {
    Queue::fake(); // no dispatch/expire side-effects; assert the money columns only

    $quote = makeDeliveryQuote(test()->restaurant, quoteAddress(), 900);

    fakeCheckoutSession(authorized: true);
    $this->withCookie(CartManager::COOKIE_NAME, uberMoneyCartCookie())
        ->post(uberMoneyHost().'/orders', uberMoneyCheckoutBody($quote->token));

    $order = payLatestCheckout(PaymentState::Authorized);

    // F = 1400 → commission 56; D = 900 → margin round(0.04×900)=36, courier 900.
    // Customer delivery line is the grossed-up 964.
    expect($order->delivery_fee_cents)->toBe(964);
    expect($order->platform_commission_cents)->toBe(56);
    expect($order->courier_fee_cents)->toBe(900);
    expect($order->delivery_margin_cents)->toBe(36);
    // Stripe gross = commission + courier + margin + tip = 56 + 900 + 36 + 500.
    expect($order->application_fee_cents)->toBe(1492);
});

it('routes the delivery margin to the founder in the revenue split', function () {
    $founder = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $founder);
    PlatformRoleHolder::assign(RevenueRole::Operator, $founder);

    Queue::fake();
    $quote = makeDeliveryQuote(test()->restaurant, quoteAddress(), 900);

    fakeCheckoutSession(authorized: true);
    $this->withCookie(CartManager::COOKIE_NAME, uberMoneyCartCookie())
        ->post(uberMoneyHost().'/orders', uberMoneyCheckoutBody($quote->token));

    $order = payLatestCheckout(PaymentState::Authorized);

    $margin = FeeDistribution::where('order_id', $order->id)
        ->where('role', RevenueRole::DeliveryMargin->value)
        ->first();

    expect($margin)->not->toBeNull();
    expect($margin->user_id)->toBe($founder->id);
    expect((int) $margin->amount_cents)->toBe(36);
});

it('leaves courier and margin untouched when the commission is capped', function () {
    test()->restaurant->forceFill(['commission_monthly_cap_cents' => 30])->save();

    Queue::fake();
    $quote = makeDeliveryQuote(test()->restaurant, quoteAddress(), 900);

    fakeCheckoutSession(authorized: true);
    $this->withCookie(CartManager::COOKIE_NAME, uberMoneyCartCookie())
        ->post(uberMoneyHost().'/orders', uberMoneyCheckoutBody($quote->token));

    $order = payLatestCheckout(PaymentState::Authorized);

    // Commission clamps to 30; the delivery margin/courier are outside the cap.
    expect($order->platform_commission_cents)->toBe(30);
    expect($order->delivery_margin_cents)->toBe(36);
    expect($order->courier_fee_cents)->toBe(900);
    // Stripe gross follows the capped commission: 30 + 900 + 36 + 500.
    expect($order->application_fee_cents)->toBe(1466);
});
