<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\CartManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

require_once __DIR__.'/CartTestHelpers.php';

function makeCustomer(Restaurant $r, string $email): User
{
    // Tenant scoping is no longer on the User row — this is a global account.
    // The $r argument is kept for callsite readability.
    return User::create([
        'is_super_admin' => false,
        'name' => 'Cust '.$email,
        'email' => $email,
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);
}

test('guest items move to user cart on login when user has no cart', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $user = makeCustomer($r, 'a@m.test');

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['simple']->id}", ['option_ids' => []]);

    expect(Cart::count())->toBe(1);
    $cart = Cart::first();
    expect($cart->user_id)->toBeNull();

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/login", [
            'email' => 'a@m.test',
            'password' => 'password',
        ]);

    expect(Cart::count())->toBe(1);
    expect(Cart::first()->user_id)->toBe($user->id);
    expect(CartItem::count())->toBe(2);
});

test('guest items merge by signature into existing user cart', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $user = makeCustomer($r, 'b@m.test');

    // Pre-existing user cart with 2x Pepperoni Pizza (default size/topping).
    app(CurrentTenant::class)->set($r);
    $userCart = Cart::create([
        'restaurant_id' => $r->id,
        'user_id' => $user->id,
        'token' => 'usercart-'.uniqid(),
        'expires_at' => now()->addDays(30),
    ]);
    $signature = app(CartManager::class)->signatureFor($f['item']->id, [$f['size_medium']->id, $f['top_pepperoni']->id]);
    CartItem::create([
        'cart_id' => $userCart->id,
        'menu_item_id' => $f['item']->id,
        'quantity' => 2,
        'unit_price_cents' => 1400,
        'modifiers' => null,
        'selection_signature' => $signature,
    ]);
    app(CurrentTenant::class)->clear();

    // Guest adds the same selection.
    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/login", [
            'email' => 'b@m.test',
            'password' => 'password',
        ]);

    // Guest cart should be gone, user cart should have a single line of qty 3.
    expect(Cart::count())->toBe(1);
    $userCart->refresh();
    expect($userCart->items()->count())->toBe(1);
    expect($userCart->items()->first()->quantity)->toBe(3);
});

test('cart on a different tenant is untouched when logging in', function () {
    // Under platform-wide accounts: one user, one set of credentials, with
    // separate carts at each tenant. Logging in on Marco's must not merge
    // or wipe their cart at Bob's.
    $a = cartFixture('marcos');
    $b = cartFixture('bobs');

    $user = makeCustomer($a['restaurant'], 'shared@m.test');

    // User already has a cart on bobs (different tenant).
    app(CurrentTenant::class)->set($b['restaurant']);
    $bobsCart = Cart::create([
        'restaurant_id' => $b['restaurant']->id,
        'user_id' => $user->id,
        'token' => 'bobs-cart',
        'expires_at' => now()->addDays(30),
    ]);
    CartItem::create([
        'cart_id' => $bobsCart->id,
        'menu_item_id' => $b['simple']->id,
        'quantity' => 1,
        'unit_price_cents' => 299,
        'modifiers' => null,
        'selection_signature' => app(CartManager::class)->signatureFor($b['simple']->id, []),
    ]);
    app(CurrentTenant::class)->clear();

    // Guest adds on marcos.
    $this->post("http://marcos.plateful.test/cart/items/{$a['simple']->id}", ['option_ids' => []]);

    // Login on marcos.
    $this->post('http://marcos.plateful.test/login', [
        'email' => 'shared@m.test',
        'password' => 'password',
    ]);

    // Bobs cart still has 1 line.
    expect($bobsCart->fresh()->items()->count())->toBe(1);
});
