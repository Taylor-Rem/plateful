<?php

use App\Mail\OrderConfirmationToCustomer;
use App\Mail\OrderNotificationToRestaurant;
use App\Models\CartItem;
use App\Models\Order;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/CartTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

test('guest can place a pickup order end-to-end', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $r->update(['tax_rate_percent' => 10, 'delivery_fee_cents' => 500]);

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'Alice Customer',
            'customer_email' => 'alice@example.test',
            'type' => 'pickup',
            'tip_preset' => '0',
        ]);

    $resp->assertRedirect();

    $order = Order::first();
    expect($order)->not->toBeNull();
    expect($order->restaurant_id)->toBe($r->id);
    expect($order->user_id)->toBeNull();
    expect($order->customer_name)->toBe('Alice Customer');
    expect($order->customer_email)->toBe('alice@example.test');
    expect($order->type->value)->toBe('pickup');
    expect($order->subtotal_cents)->toBe(1400);
    expect($order->tax_cents)->toBe(140); // 10% of 1400
    expect($order->delivery_fee_cents)->toBe(0); // pickup
    expect($order->tip_cents)->toBe(0);
    expect($order->total_cents)->toBe(1540);
    expect($order->number)->toMatch('/^[A-Z]{3}-[A-Z0-9]{5}$/');
    expect($order->confirmation_token)->not->toBeNull();
    expect(strlen($order->confirmation_token))->toBe(64);

    expect($order->items()->count())->toBe(1);
    $line = $order->items()->first();
    expect($line->name)->toBe('Pep');
    expect($line->unit_price_cents)->toBe(1400);
    expect($line->quantity)->toBe(1);
    expect($line->subtotal_cents)->toBe(1400);

    expect(CartItem::count())->toBe(0);

    $resp->assertCookie('plateful_recent_order');

    Mail::assertQueued(OrderConfirmationToCustomer::class);
    Mail::assertQueued(OrderNotificationToRestaurant::class);

    $resp->assertRedirectContains("/orders/{$order->number}");
});

test('placing a delivery order computes delivery fee and snapshots address', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $r->update(['tax_rate_percent' => 8, 'delivery_fee_cents' => 499]);

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'Bob',
            'customer_email' => 'bob@example.test',
            'customer_phone' => '555-1212',
            'type' => 'delivery',
            'delivery_address' => [
                'street' => '123 Main',
                'city' => 'NYC',
                'state' => 'NY',
                'postal_code' => '10001',
                'instructions' => 'Buzz #4',
            ],
            'tip_preset' => '15',
        ]);

    $order = Order::first();
    expect($order)->not->toBeNull();
    expect($order->type->value)->toBe('delivery');
    expect($order->delivery_fee_cents)->toBe(499);
    expect($order->tip_cents)->toBe(210); // 15% of 1400
    expect($order->tax_cents)->toBe(112); // round(1400*0.08)
    expect($order->total_cents)->toBe(1400 + 112 + 499 + 210);
    expect($order->delivery_address)->toMatchArray([
        'street' => '123 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
        'instructions' => 'Buzz #4',
    ]);
});

test('placing an order writes an initial pending order_event', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
            'tip_preset' => '0',
        ]);

    $order = Order::first();
    expect($order)->not->toBeNull();
    $events = $order->events()->orderBy('id')->get();
    expect($events)->toHaveCount(1);
    expect($events->first()->from_status)->toBeNull();
    expect($events->first()->to_status->value)->toBe('pending');
});

test('after placing an order the cart is empty on the next page load', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
            'tip_preset' => '0',
        ]);

    expect(CartItem::count())->toBe(0);
});
