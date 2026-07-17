<?php

use App\Enums\DeliveryMode;
use App\Enums\DeliveryProviderName;
use App\Enums\PaymentState;
use App\Enums\RevenueRole;
use App\Models\DeliveryIntegration;
use App\Models\DeliveryQuote;
use App\Models\FeeDistribution;
use App\Models\PlatformRoleHolder;
use App\Models\User;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryQuoteTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.stripe_variable_rate' => 0.029]);
    Mail::fake();

    $f = cartFixture();
    $this->fixture = $f;
    $this->restaurant = $f['restaurant'];
    $this->restaurant->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['doordash'],
        'tax_rate_percent' => 0,
        'prep_time_minutes' => 10,
        'phone' => '5551234567',
        'application_fee_percent' => 4,
    ]);

    DeliveryIntegration::factory()->doordash()->create([
        'restaurant_id' => $this->restaurant->id,
        'external_store_id' => 'store_dd_money',
    ]);
});

function ddHost(): string
{
    return 'http://'.test()->restaurant->subdomain.'.plateful.test';
}

function ddCartCookie(): string
{
    $f = test()->fixture;

    $response = test()->post(ddHost()."/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    return cartCookieFrom($response);
}

/**
 * A stored DoorDash quote as the quote endpoint would have written it.
 */
function makeDoorDashQuote(int $feeCents): DeliveryQuote
{
    return DeliveryQuote::withoutTenantScope()->create([
        'token' => (string) Str::uuid(),
        'restaurant_id' => test()->restaurant->id,
        'provider' => DeliveryProviderName::DoorDash,
        'external_quote_id' => 'pf-'.Str::random(12),
        'dropoff_address' => quoteAddress(),
        'fee_cents' => $feeCents,
        'eta_minutes' => 30,
        'expires_at' => now()->addMinutes(5),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ddCheckoutBody(string $token, array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Bob',
        'customer_email' => 'bob@example.test',
        'type' => 'delivery',
        'delivery_address' => quoteAddress(),
        'delivery_quote_token' => $token,
        'tip_preset' => 'custom',
        'tip_custom_cents' => 500,
    ], $overrides);
}

it('quotes a grossed-up delivery fee for a DoorDash restaurant', function () {
    // DoorDash returns the raw courier fee; the customer sees it grossed up so
    // the restaurant bears no Stripe fee on the delivery line.
    Http::fake(['openapi.doordash.com/*' => Http::response([
        'external_delivery_id' => 'pf-quote-x',
        'fee' => 900,
        'duration' => 30,
    ])]);

    $response = $this->withCookie(CartManager::COOKIE_NAME, ddCartCookie())
        ->postJson(ddHost().'/checkout/delivery-quote', ['address' => quoteAddress()]);

    $response->assertOk();
    // round(900 × 1.04 / 0.971) = 964
    expect($response->json('quote.feeCents'))->toBe(964);
    // Central billing can re-quote, so the price is customer-visible → countdown.
    expect($response->json('quote.expiresAt'))->not->toBeNull();
});

it('recovers courier + margin + tip through the application fee', function () {
    Queue::fake(); // no dispatch/expire side-effects; assert the money columns only

    $quote = makeDoorDashQuote(900);

    fakeCheckoutSession(authorized: true);
    $this->withCookie(CartManager::COOKIE_NAME, ddCartCookie())
        ->post(ddHost().'/orders', ddCheckoutBody($quote->token));

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
    $overseer = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $founder);
    PlatformRoleHolder::assign(RevenueRole::Operator, $founder);
    $this->restaurant->update(['overseer_id' => $overseer->id]);

    Queue::fake();
    $quote = makeDoorDashQuote(900);

    fakeCheckoutSession(authorized: true);
    $this->withCookie(CartManager::COOKIE_NAME, ddCartCookie())
        ->post(ddHost().'/orders', ddCheckoutBody($quote->token));

    $order = payLatestCheckout(PaymentState::Authorized);

    $margin = FeeDistribution::where('order_id', $order->id)
        ->where('role', RevenueRole::DeliveryMargin->value)
        ->first();

    expect($margin)->not->toBeNull();
    expect($margin->user_id)->toBe($founder->id);
    expect((int) $margin->amount_cents)->toBe(36);

    // The commission (56) still splits by the role shares, separately.
    expect((int) FeeDistribution::where('order_id', $order->id)
        ->whereIn('role', [RevenueRole::Founder->value, RevenueRole::Overseer->value])
        ->sum('amount_cents'))->toBe(56);
});

it('leaves courier and margin untouched when the commission is capped', function () {
    $this->restaurant->forceFill(['commission_monthly_cap_cents' => 30])->save();

    Queue::fake();
    $quote = makeDoorDashQuote(900);

    fakeCheckoutSession(authorized: true);
    $this->withCookie(CartManager::COOKIE_NAME, ddCartCookie())
        ->post(ddHost().'/orders', ddCheckoutBody($quote->token));

    $order = payLatestCheckout(PaymentState::Authorized);

    // Commission clamps to 30; the delivery margin/courier are outside the cap.
    expect($order->platform_commission_cents)->toBe(30);
    expect($order->delivery_margin_cents)->toBe(36);
    expect($order->courier_fee_cents)->toBe(900);
    // Stripe gross follows the capped commission: 30 + 900 + 36 + 500.
    expect($order->application_fee_cents)->toBe(1466);
});
