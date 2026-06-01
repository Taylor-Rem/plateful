<?php

use App\Http\Controllers\Storefront\CheckoutController;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

function placeGuestOrder($t, array $f, string $cookie): Order
{
    $r = $f['restaurant'];
    fakeCheckoutSession();
    $t->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'G',
            'customer_email' => 'g@g.test',
            'type' => 'pickup',
        ]);

    return payLatestCheckout();
}

function addLineCookie($t, array $f): string
{
    $r = $f['restaurant'];
    $first = $t->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    return cartCookieFrom($first);
}

test('guest with confirmation cookie can view their order', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addLineCookie($this, $f);
    $order = placeGuestOrder($this, $f, $cookie);

    $resp = $this->withCookie(CheckoutController::RECENT_ORDER_COOKIE, $order->confirmation_token)
        ->get("http://{$r->subdomain}.plateful.test/orders/{$order->number}");

    $resp->assertOk()
        ->assertInertia(fn ($p) => $p->component('Storefront/OrderConfirmation'));
});

test('guest without confirmation cookie gets 404', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addLineCookie($this, $f);
    $order = placeGuestOrder($this, $f, $cookie);

    $resp = $this->get("http://{$r->subdomain}.plateful.test/orders/{$order->number}");

    $resp->assertNotFound();
});

test('logged-in user can view their own order', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $user = User::create([
        'name' => 'C', 'email' => 'c@c.test',
        'password' => Hash::make('pwd'),
        'is_super_admin' => false,
    ]);

    $first = $this->actingAs($user)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
            'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
        ]);
    $cookie = cartCookieFrom($first);

    fakeCheckoutSession();
    $this->actingAs($user)
        ->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'C',
            'customer_email' => 'c@c.test',
            'type' => 'pickup',
        ]);

    $order = payLatestCheckout();
    expect($order->user_id)->toBe($user->id);

    $resp = $this->actingAs($user)
        ->get("http://{$r->subdomain}.plateful.test/orders/{$order->number}");
    $resp->assertOk();
});

test('different user gets 404', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addLineCookie($this, $f);
    $order = placeGuestOrder($this, $f, $cookie);

    $stranger = User::create([
        'name' => 'S', 'email' => 's@s.test',
        'password' => Hash::make('pwd'),
        'is_super_admin' => false,
    ]);

    $resp = $this->actingAs($stranger)
        ->get("http://{$r->subdomain}.plateful.test/orders/{$order->number}");
    $resp->assertNotFound();
});

test('order placed on one tenant is 404 on a different tenant', function () {
    $f = cartFixture('marcos');
    $cookie = addLineCookie($this, $f);
    $order = placeGuestOrder($this, $f, $cookie);

    // Make a second tenant
    $r2 = Restaurant::create([
        'name' => "Bob's", 'subdomain' => 'bobs', 'email' => 'b@b.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);

    $resp = $this->withCookie(CheckoutController::RECENT_ORDER_COOKIE, $order->confirmation_token)
        ->get("http://{$r2->subdomain}.plateful.test/orders/{$order->number}");

    $resp->assertNotFound();
});
