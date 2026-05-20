<?php

use App\Models\CartItem;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

require_once __DIR__.'/CartTestHelpers.php';

test('cart cookie issued on one tenant does not surface on another tenant', function () {
    $a = cartFixture('marcos');
    $b = cartFixture('bobs');

    $resp = $this->post("http://marcos.plateful.test/cart/items/{$a['simple']->id}", ['option_ids' => []]);

    $cookies = $resp->headers->getCookies();
    $cartCookie = collect($cookies)->first(fn ($c) => $c->getName() === CartManager::COOKIE_NAME);
    expect($cartCookie)->not->toBeNull();

    $token = $cartCookie->getValue();

    // Visit bobs with the same cookie token; cart should be null there.
    $this->withCookie(CartManager::COOKIE_NAME, $token)
        ->get('http://bobs.plateful.test/')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('cart', null));
});

test('updating a cart item from another tenant returns 404', function () {
    $a = cartFixture('marcos');
    $b = cartFixture('bobs');

    $this->post("http://marcos.plateful.test/cart/items/{$a['simple']->id}", ['option_ids' => []]);
    $line = CartItem::first();

    // Bobs has no cart; updating marcos's item via bobs host should 404.
    $this->patch("http://bobs.plateful.test/cart/items/{$line->id}", ['quantity' => 5])
        ->assertNotFound();
});
