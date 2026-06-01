<?php

use App\Mail\OrderConfirmationToCustomer;
use App\Mail\OrderNotificationToRestaurant;
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

test('customer confirmation mailable queued to customer email', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'customer@example.test',
            'type' => 'pickup',
        ]);

    payLatestCheckout();

    Mail::assertQueued(OrderConfirmationToCustomer::class, function ($mail) {
        return $mail->hasTo('customer@example.test');
    });
});

test('restaurant notification mailable queued to restaurant email', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    payLatestCheckout();

    Mail::assertQueued(OrderNotificationToRestaurant::class, function ($mail) use ($r) {
        return $mail->hasTo($r->email);
    });
});

test('restaurant notification NOT queued when restaurant.email is null', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    // email is required at table level; null it via raw SQL
    $r->email = '';
    $r->save();
    // Reload model from DB to ensure the OrderPlacement sees the empty value.
    $r->refresh();

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    payLatestCheckout();

    Mail::assertQueued(OrderConfirmationToCustomer::class);
    Mail::assertNotQueued(OrderNotificationToRestaurant::class);
});
