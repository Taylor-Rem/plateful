<?php

use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryMode;
use App\Models\DeliveryIntegration;
use App\Models\DeliveryQuote;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Services\CartManager;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryQuoteTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();

    $f = cartFixture();
    $this->fixture = $f;
    $this->restaurant = $f['restaurant'];
    $this->restaurant->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        // This suite exercises the Uber path (it fakes Uber's API), so it
        // prioritizes Uber explicitly rather than riding the default chain,
        // which is DoorDash (the launch provider).
        'delivery_provider_priority' => ['uber'],
        'delivery_fee_strategy' => DeliveryFeeStrategy::PassThrough,
        'delivery_fee_cents' => 499,
        'tax_rate_percent' => 0,
        'prep_time_minutes' => 10,
        'phone' => '5551234567',
    ]);

    DeliveryIntegration::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'customer_id' => 'cust_quote',
        'access_token' => 'tok_live',
        'token_expires_at' => now()->addDays(20),
    ]);
});

function quoteHost(): string
{
    return 'http://'.test()->restaurant->subdomain.'.plateful.test';
}

/**
 * A cart with one line, returning the cookie that identifies it.
 */
function quoteCartCookie(): string
{
    $f = test()->fixture;

    $response = test()->post(quoteHost()."/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    return cartCookieFrom($response);
}

function fakeUberQuote(int $fee = 799): void
{
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(['access_token' => 't', 'expires_in' => 2592000]),
        'api.uber.com/*' => Http::response([
            'id' => 'dqt_live_1',
            'fee' => $fee,
            'duration' => 44,
            'pickup_duration' => 18,
            'expires' => now()->addMinutes(15)->toIso8601String(),
            'dropoff_eta' => now()->addMinutes(44)->toIso8601String(),
        ]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function quotePayload(array $overrides = []): array
{
    return ['address' => array_merge([
        'street' => '285 Fulton St',
        'city' => 'New York',
        'state' => 'NY',
        'postal_code' => '10006',
        'country' => 'US',
    ], $overrides)];
}

it('quotes delivery for an address before any payment', function () {
    fakeUberQuote(799);

    $response = $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload());

    $response->assertOk();
    expect($response->json('quote.feeCents'))->toBe(799);
    expect($response->json('quote.token'))->toBeString();
    expect($response->json('quote.expiresAt'))->not->toBeNull();

    // The quote is persisted, because the fee can never come back from a client.
    expect(DeliveryQuote::withoutTenantScope()->count())->toBe(1);
});

it('adds kitchen prep time to the ETA the customer is shown', function () {
    fakeUberQuote();

    $response = $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload());

    // Uber's 44-minute duration assumes the food is ready NOW; the ticket takes
    // 10. Quoting 44 would promise a time the kitchen cannot meet.
    expect($response->json('quote.etaMinutes'))->toBe(54);
});

it('charges the quoted fee, not the restaurant’s advertised one', function () {
    fakeUberQuote(799);

    $token = $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload())->json('quote.token');
    $order = placeQuotedOrder($token);

    // The restaurant's flat 499 is irrelevant under pass-through — the customer
    // pays what delivery actually costs.
    expect($order->delivery_fee_cents)->toBe(799);
    expect($order->delivery_quote_token)->toBe($token);
});

it('charges the advertised fee under absorb, and the restaurant eats the delta', function () {
    test()->restaurant->update(['delivery_fee_strategy' => DeliveryFeeStrategy::Absorb]);
    fakeUberQuote(920);

    $response = $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload());

    // Advertised 499 against a 920 courier cost: the customer sees 499 and the
    // restaurant absorbs 421 — chosen, where today it happens by accident.
    expect($response->json('quote.feeCents'))->toBe(499);

    $order = placeQuotedOrder($response->json('quote.token'));
    expect($order->delivery_fee_cents)->toBe(499);

    // Nothing to count down when the customer's price cannot move.
    expect($response->json('quote.expiresAt'))->toBeNull();
});

it('refuses checkout with no quote at all', function () {
    $response = $this->withCookie(CartManager::COOKIE_NAME, quoteCartCookie())
        ->post(quoteHost().'/orders', checkoutBody(null), ['Accept' => 'application/json']);

    // The whole point of the rework: nobody is charged for a delivery that was
    // never priced.
    $response->assertStatus(422);
    expect(array_keys($response->json('errors')))->toContain('delivery_quote_token');
    expect(PendingCheckout::count())->toBe(0);
});

it('refuses a quote token belonging to another restaurant', function () {
    $other = cartFixture('otherco')['restaurant'];
    $stolen = makeDeliveryQuote($other, quoteAddress(), 100);

    $response = $this->withCookie(CartManager::COOKIE_NAME, quoteCartCookie())
        ->post(quoteHost().'/orders', checkoutBody($stolen->token), ['Accept' => 'application/json']);

    // Otherwise a $1 quote from a cheap restaurant prices delivery here.
    $response->assertStatus(422);
    expect(PendingCheckout::count())->toBe(0);
});

it('refuses a quote priced for a different address', function () {
    fakeUberQuote(799);
    $token = $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload())->json('quote.token');

    $response = $this->withCookie(CartManager::COOKIE_NAME, quoteCartCookie())
        ->post(quoteHost().'/orders', checkoutBody($token, [
            'street' => '1 Faraway Rd',
            'city' => 'Anchorage',
            'state' => 'AK',
            'postal_code' => '99501',
        ]), ['Accept' => 'application/json']);

    // Quote cheap nearby, deliver expensive far away — the exact substitution
    // this guard exists to stop.
    $response->assertStatus(422);
    expect(array_keys($response->json('errors')))->toContain('delivery_quote_token');
});

it('refuses an expired quote', function () {
    $expired = makeDeliveryQuote(test()->restaurant, quoteAddress(), 799, now()->subMinute()->toIso8601String());

    $response = $this->withCookie(CartManager::COOKIE_NAME, quoteCartCookie())
        ->post(quoteHost().'/orders', checkoutBody($expired->token, [
            'street' => '285 Fulton St', 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10006',
        ]), ['Accept' => 'application/json']);

    $response->assertStatus(422);
    expect(array_keys($response->json('errors')))->toContain('delivery_quote_token');
});

it('lets an edit to delivery instructions keep the quote', function () {
    fakeUberQuote(799);
    $token = $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload())->json('quote.token');

    // Instructions don't move the courier, so changing them must not re-price.
    $order = placeQuotedOrder($token, ['instructions' => 'Leave at the door']);

    expect($order->delivery_fee_cents)->toBe(799);
});

it('does not offer delivery when the provider cannot quote the address', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(['access_token' => 't', 'expires_in' => 2592000]),
        'api.uber.com/*' => Http::response(['message' => 'address undeliverable'], 400),
    ]);

    $response = $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload());

    // A failed quote IS the out-of-range check — no radius maths, no zone table.
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('can’t deliver to that address');
    expect(DeliveryQuote::withoutTenantScope()->count())->toBe(0);
});

it('does not quote for a restaurant with delivery switched off', function () {
    test()->restaurant->update(['delivery_enabled' => false]);
    Http::fake();

    $this->postJson(quoteHost().'/checkout/delivery-quote', quotePayload())->assertStatus(422);
    Http::assertNothingSent();
});

it('requires a complete address to quote', function () {
    Http::fake();

    $this->postJson(quoteHost().'/checkout/delivery-quote', ['address' => ['street' => '285 Fulton St']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['address.city', 'address.state', 'address.postal_code']);

    Http::assertNothingSent();
});

/**
 * @param  array<string, mixed>  $addressOverrides
 */
function checkoutBody(?string $token, array $addressOverrides = []): array
{
    return [
        'customer_name' => 'Bob',
        'customer_email' => 'bob@example.test',
        'type' => 'delivery',
        'delivery_address' => array_merge([
            'street' => '285 Fulton St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10006',
            'country' => 'US',
        ], $addressOverrides),
        'delivery_quote_token' => $token,
        'tip_preset' => '0',
    ];
}

/**
 * @param  array<string, mixed>  $addressOverrides
 */
function placeQuotedOrder(?string $token, array $addressOverrides = []): Order
{
    fakeCheckoutSession();

    test()->withCookie(CartManager::COOKIE_NAME, quoteCartCookie())
        ->post(quoteHost().'/orders', checkoutBody($token, $addressOverrides));

    return payLatestCheckout();
}
