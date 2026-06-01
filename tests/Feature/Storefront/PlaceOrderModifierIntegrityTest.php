<?php

use App\Models\MenuItem;
use App\Models\Order;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

function modAddPep($t, array $f): string
{
    $r = $f['restaurant'];
    $resp = $t->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    return cartCookieFrom($resp);
}

function modPlace($t, array $f, string $cookie)
{
    $r = $f['restaurant'];

    return $t->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ], ['Accept' => 'application/json']);
}

test('checkout rejects line when its item template was removed after cart-add', function () {
    $f = cartFixture();
    $cookie = modAddPep($this, $f);

    // Strip the template from the item between cart-add and checkout.
    MenuItem::withoutTenantScope()->find($f['item']->id)->update(['item_template_id' => null]);

    $resp = modPlace($this, $f, $cookie);

    expect($resp->status())->toBeIn([422, 302]);
    expect(Order::count())->toBe(0);
});

test('checkout rejects line when a non-default option price changed after cart-add', function () {
    $f = cartFixture();
    // Add an item that includes bacon (non-default topping with +300 delta).
    $r = $f['restaurant'];
    $resp = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id, $f['top_bacon']->id],
    ]);
    $cookie = cartCookieFrom($resp);

    // Change bacon delta from +300 to +800 — cart's stored unit price is now stale.
    $f['top_bacon']->update(['price_delta_cents' => 800]);

    $resp = modPlace($this, $f, $cookie);

    expect($resp->status())->toBeIn([422, 302]);
    expect(Order::count())->toBe(0);
});

test('checkout rejects line when group min was tightened after cart-add', function () {
    $f = cartFixture();
    // Add an item with no toppings selected (toppings is min 0)
    $r = $f['restaurant'];
    $resp = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id],
    ]);
    $cookie = cartCookieFrom($resp);

    // Tighten Toppings to require min 1 after the fact.
    $f['top_pepperoni']->group->update(['min_selections' => 1]);

    $resp = modPlace($this, $f, $cookie);

    expect($resp->status())->toBeIn([422, 302]);
    expect(Order::count())->toBe(0);
});

test('checkout still succeeds when nothing has changed', function () {
    $f = cartFixture();
    $cookie = modAddPep($this, $f);

    fakeCheckoutSession();
    modPlace($this, $f, $cookie);
    payLatestCheckout();

    expect(Order::count())->toBe(1);
});
