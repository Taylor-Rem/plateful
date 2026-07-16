<?php

use App\Enums\DeliveryMode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

function addPepperoniLine($t, array $f): string
{
    $r = $f['restaurant'];
    $first = $t->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    return cartCookieFrom($first);
}

test('cannot place order with empty cart', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $resp = $this->post("http://{$r->subdomain}.plateful.test/orders", [
        'customer_name' => 'A',
        'customer_email' => 'a@a.test',
        'type' => 'pickup',
    ], ['Accept' => 'application/json']);

    expect($resp->status())->toBeIn([403, 422, 302]);
    expect(Order::count())->toBe(0);
});

test('missing customer_name fails validation', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    expect($resp->json('errors'))->toHaveKey('customer_name');
});

test('invalid email fails validation', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'not-an-email',
            'type' => 'pickup',
        ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    expect($resp->json('errors'))->toHaveKey('customer_email');
});

test('delivery without address fails validation', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'delivery',
        ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    $errors = $resp->json('errors');
    expect(array_keys($errors))->toContain('delivery_address.street');
});

test('delivery order is rejected when the restaurant has delivery disabled', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    expect($r->fresh()->delivery_enabled)->toBeFalse();
    $cookie = addPepperoniLine($this, $f);

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'delivery',
            'delivery_address' => [
                'street' => '123 Main',
                'city' => 'NYC',
                'state' => 'NY',
                'postal_code' => '10001',
            ],
            'tip_preset' => '0',
        ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    expect(array_keys($resp->json('errors')))->toContain('type');

    // No Stripe session may be opened for an order we won't dispatch.
    expect(PendingCheckout::count())->toBe(0);
});

test('delivery order is accepted when the restaurant has delivery enabled', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    // Self-delivery: priced from the restaurant's own fee, no quote involved.
    $r->update([
        'delivery_enabled' => true,
        'delivery_fee_cents' => 499,
        'delivery_mode' => DeliveryMode::SelfDelivery,
    ]);
    $cookie = addPepperoniLine($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'delivery',
            'delivery_address' => [
                'street' => '123 Main',
                'city' => 'NYC',
                'state' => 'NY',
                'postal_code' => '10001',
            ],
            'tip_preset' => '0',
        ]);

    $order = payLatestCheckout();
    expect($order->type->value)->toBe('delivery');
    expect($order->delivery_fee_cents)->toBe(499);
});

test('pickup with address provided is accepted and address is ignored', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
            'delivery_address' => [
                'street' => '1 Foo',
                'city' => 'X',
                'state' => 'YY',
                'postal_code' => '11111',
            ],
            'tip_preset' => '0',
        ]);

    $order = payLatestCheckout();
    expect($order)->not->toBeNull();
    expect($order->type->value)->toBe('pickup');
    expect($order->delivery_address)->toBeNull();
});

test('cart with an unavailable item fails 422 with field error', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    $f['item']->update(['is_available' => false]);

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    $errors = $resp->json('errors');
    $haystack = json_encode($errors);
    expect($haystack)->toContain('no longer available');
});

test('tip preset 15 produces tip_cents equal to round of 15% of subtotal', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
            'tip_preset' => '15',
        ]);

    $order = payLatestCheckout();
    expect($order->tip_cents)->toBe((int) round(1400 * 0.15));
});

test('tip preset custom with $5 produces tip_cents of 500', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
            'tip_preset' => 'custom',
            'tip_custom_cents' => 500,
        ]);

    $order = payLatestCheckout();
    expect($order->tip_cents)->toBe(500);
});

test('tax_cents matches restaurant tax_rate_percent', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $r->update(['tax_rate_percent' => 8.875]);
    $cookie = addPepperoniLine($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    $order = payLatestCheckout();
    expect($order->tax_cents)->toBe((int) round(1400 * 8.875 / 100));
});

test('order number format matches expected pattern', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    expect(payLatestCheckout()->number)->toMatch('/^[A-Z]{3}-[A-Z0-9]{5}$/');
});

test('order number collisions are retried until unique', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPepperoniLine($this, $f);

    // Pre-seed an order with a number Str::random would never collide with realistically;
    // simulate forcing collision by inserting first, then placing a regular order.
    // We achieve coverage by checking the service handles a unique violation gracefully:
    // create an existing order with a specific number, then mock the random sequence.

    // Insert a row with a known number "MAR-AAAAA"
    Order::create([
        'restaurant_id' => $r->id,
        'user_id' => null,
        'customer_name' => 'pre',
        'customer_email' => 'p@p.test',
        'number' => 'MAR-AAAAA',
        'status' => OrderStatus::Pending,
        'type' => OrderType::Pickup,
        'subtotal_cents' => 0,
        'tax_cents' => 0,
        'tip_cents' => 0,
        'delivery_fee_cents' => 0,
        'application_fee_cents' => 0,
        'total_cents' => 0,
        'placed_at' => now(),
    ]);

    fakeCheckoutSession();

    // Force Str::random to return AAAAA the first 2 calls, then BBBBB.
    $calls = 0;
    Str::createRandomStringsUsing(function () use (&$calls) {
        $calls++;

        return $calls <= 1 ? 'AAAAA' : 'BBBBB';
    });

    $newOrder = null;
    try {
        $this->withCookie(CartManager::COOKIE_NAME, $cookie)
            ->post("http://{$r->subdomain}.plateful.test/orders", [
                'customer_name' => 'A',
                'customer_email' => 'a@a.test',
                'type' => 'pickup',
            ]);
        $newOrder = payLatestCheckout();
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($newOrder)->not->toBeNull();
    expect($newOrder->number)->toBe('MAR-BBBBB');
    expect($calls)->toBeGreaterThanOrEqual(2);
});

test('order placement is rate limited', function () {
    // Every hit to this endpoint creates a real Stripe Checkout Session, so it
    // must never be unmetered once live keys exist (card-testing target).
    $route = Route::getRoutes()->getByName('storefront.orders.store');

    expect($route->gatherMiddleware())->toContain('throttle:10,1');
});
