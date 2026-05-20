<?php

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__.'/CartTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

/**
 * Build hours for the restaurant so it is currently CLOSED at the test "now".
 * We set hours only on a day other than today.
 */
function makeClosedNow(Restaurant $r): void
{
    $tz = $r->timezone ?: 'America/New_York';
    $now = Carbon::now()->setTimezone($tz);
    $today = (int) $now->dayOfWeek;
    $otherDay = ($today + 2) % 7;

    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => $otherDay,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);
}

function makeOpenNow(Restaurant $r): void
{
    // Create a window that covers the full current day.
    $tz = $r->timezone ?: 'America/New_York';
    $today = (int) Carbon::now()->setTimezone($tz)->dayOfWeek;
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => $today,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
        'position' => 0,
    ]);
}

test('storefront response includes isOpen true when always open (no rows)', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('restaurant.isOpen', true)
            ->where('restaurant.nextOpenLabel', null)
        );
});

test('storefront response shows isOpen false and a label when closed', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    makeClosedNow($r);

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('restaurant.isOpen', false)
            ->whereNot('restaurant.nextOpenLabel', null)
        );
});

test('cart add still works when restaurant is closed', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    makeClosedNow($r);

    $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ])->assertRedirect();
});

test('POST /orders is rejected when restaurant is closed', function () {
    $f = cartFixture();
    $r = $f['restaurant'];

    // First add the item while still open (no rows).
    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    // Now close the restaurant.
    makeClosedNow($r);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'Alice',
            'customer_email' => 'alice@example.test',
            'type' => 'pickup',
            'tip_preset' => '0',
        ])
        ->assertSessionHasErrors('restaurant_closed');

    expect(Order::count())->toBe(0);
});

test('order placement succeeds when restaurant is explicitly open', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    makeOpenNow($r);

    $first = $this->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'Alice',
            'customer_email' => 'alice@example.test',
            'type' => 'pickup',
            'tip_preset' => '0',
        ])
        ->assertRedirect();

    expect(Order::count())->toBe(1);
});
