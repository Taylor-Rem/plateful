<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/CartTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('adding an item creates a cart with cookie and line item', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $item = $f['item'];

    $resp = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    $resp->assertRedirect();

    expect(Cart::count())->toBe(1);
    $cart = Cart::first();
    expect($cart->restaurant_id)->toBe($r->id);

    expect($cart->items()->count())->toBe(1);
    $line = $cart->items()->first();
    expect($line->menu_item_id)->toBe($item->id);
    expect($line->unit_price_cents)->toBe(1400);
    expect($line->quantity)->toBe(1);
    expect($line->selection_signature)->not->toBeNull();

    $resp->assertCookie(CartManager::COOKIE_NAME);
});

test('adding same item with same selections increments quantity, no new line', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $item = $f['item'];

    $payload = ['option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id]];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", $payload);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", $payload);

    expect(CartItem::count())->toBe(1);
    expect(CartItem::first()->quantity)->toBe(2);
});

test('adding same item with different selections creates a separate line', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $item = $f['item'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", [
            'option_ids' => [$f['size_medium']->id, $f['top_bacon']->id],
        ]);

    expect(CartItem::count())->toBe(2);
});

test('adding an item with an option from a different template fails 422', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $item = $f['item'];

    $resp = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", [
        'option_ids' => [$f['size_medium']->id, $f['other_template_option']->id],
    ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    expect(CartItem::count())->toBe(0);
});

test('adding an item violating min selections fails with group name in message', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $item = $f['item'];

    $resp = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", [
        'option_ids' => [],
    ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    $errors = $resp->json('errors.option_ids', []);
    $blob = implode(' ', (array) $errors);
    expect($blob)->toContain('Size');
});

test('adding an item violating max selections fails with group name in message', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $item = $f['item'];

    $resp = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$item->id}", [
        'option_ids' => [$f['size_small']->id, $f['size_medium']->id],
    ], ['Accept' => 'application/json']);

    $resp->assertStatus(422);
    $errors = $resp->json('errors.option_ids', []);
    $blob = implode(' ', (array) $errors);
    expect($blob)->toContain('Size');
});

test('updating quantity persists', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $line = CartItem::first();

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->patch("http://{$r->subdomain}.plateful.test/cart/items/{$line->id}", [
            'quantity' => 4,
        ])->assertRedirect();

    expect($line->fresh()->quantity)->toBe(4);
});

test('updating quantity to 0 removes the line', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $line = CartItem::first();

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->patch("http://{$r->subdomain}.plateful.test/cart/items/{$line->id}", [
            'quantity' => 0,
        ]);

    expect(CartItem::count())->toBe(0);
});

test('removing a line works', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $line = CartItem::first();

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->delete("http://{$r->subdomain}.plateful.test/cart/items/{$line->id}");

    expect(CartItem::count())->toBe(0);
});

test('clearing cart removes all items', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['simple']->id}", [
            'option_ids' => [],
        ]);

    expect(CartItem::count())->toBe(2);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->delete("http://{$r->subdomain}.plateful.test/cart");

    expect(CartItem::count())->toBe(0);
});

test('server computes price ignoring tampered client price field', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
        'unit_price_cents' => 1,
        'price_cents' => 1,
    ]);

    expect(CartItem::first()->unit_price_cents)->toBe(1400);
});

test('cart shared prop is null on fresh visit', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('cart', null));
});

test('cart shared prop is populated after adding', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['simple']->id}", [
            'option_ids' => [],
        ]);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('cart')
            ->where('cart.itemCount', 2)
            ->where('cart.subtotalCents', 1400 + 299)
            ->has('cart.items', 2)
        );
});

test('subtotal updates with multiple lines and quantities', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $line = CartItem::first();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->patch("http://{$r->subdomain}.plateful.test/cart/items/{$line->id}", ['quantity' => 3]);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['simple']->id}", ['option_ids' => []]);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($p) => $p
            ->where('cart.itemCount', 4)
            ->where('cart.subtotalCents', 1400 * 3 + 299)
        );
});

test('after placing an order, the cart is empty', function () {
    Mail::fake();
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    expect(CartItem::count())->toBe(1);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    expect(CartItem::count())->toBe(0);
});

test('cart item shows isAvailable false when underlying menu item is unavailable', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $f['item']->update(['is_available' => false]);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($p) => $p
            ->where('cart.items.0.isAvailable', false)
        );
});
