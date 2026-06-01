<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use App\Services\CartManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Storefront/CartTestHelpers.php';
require_once __DIR__.'/Storefront/CheckoutTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

test('signing up at a restaurant creates a restaurant_customer pivot row', function () {
    $r = Restaurant::create([
        'name' => 'M', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);

    $this->post("http://{$r->subdomain}.plateful.test/register", [
        'name' => 'Alice',
        'email' => 'alice@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'alice@example.test')->first();
    expect(RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $r->id)
        ->exists())->toBeTrue();
});

test('placing a first order creates a pivot row and sets counters', function () {
    $f = cartFixture('marcos');
    $r = $f['restaurant'];

    // Existing global account (no pivot yet) — simulates a user who signed up
    // at restaurant Y, now ordering at restaurant X for the first time.
    $user = User::create([
        'name' => 'Bob', 'email' => 'bob@example.test',
        'password' => Hash::make('password'), 'is_super_admin' => false,
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
            'customer_name' => 'Bob',
            'customer_email' => 'bob@example.test',
            'type' => 'pickup',
        ]);
    payLatestCheckout();

    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $r->id)
        ->first();
    expect($pivot)->not->toBeNull();
    expect((int) $pivot->total_orders)->toBe(1);
    expect((int) $pivot->total_spent_cents)->toBeGreaterThan(0);
    expect($pivot->first_ordered_at)->not->toBeNull();
    expect($pivot->last_ordered_at)->not->toBeNull();
});

test('subsequent orders increment counters on the same pivot row', function () {
    $f = cartFixture('marcos');
    $r = $f['restaurant'];

    $user = User::create([
        'name' => 'Carla', 'email' => 'carla@example.test',
        'password' => Hash::make('password'), 'is_super_admin' => false,
    ]);

    // Pre-existing pivot from signup (no orders yet).
    RestaurantCustomer::create([
        'user_id' => $user->id,
        'restaurant_id' => $r->id,
    ]);

    // Two consecutive orders.
    fakeCheckoutSession();
    for ($i = 0; $i < 2; $i++) {
        $first = $this->actingAs($user)
            ->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
                'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
            ]);
        $cookie = cartCookieFrom($first);

        $this->actingAs($user)
            ->withCookie(CartManager::COOKIE_NAME, $cookie)
            ->post("http://{$r->subdomain}.plateful.test/orders", [
                'customer_name' => 'Carla',
                'customer_email' => 'carla@example.test',
                'type' => 'pickup',
            ]);
        payLatestCheckout();
    }

    expect(RestaurantCustomer::count())->toBe(1);
    $pivot = RestaurantCustomer::first();
    expect((int) $pivot->total_orders)->toBe(2);
});

test('a customer ordering at two different restaurants has two independent pivot rows', function () {
    $a = cartFixture('marcos');
    $b = cartFixture('bobs');

    $user = User::create([
        'name' => 'Dana', 'email' => 'dana@example.test',
        'password' => Hash::make('password'), 'is_super_admin' => false,
    ]);

    fakeCheckoutSession();
    foreach ([$a, $b] as $fx) {
        $r = $fx['restaurant'];
        $first = $this->actingAs($user)
            ->post("http://{$r->subdomain}.plateful.test/cart/items/{$fx['item']->id}", [
                'option_ids' => [$fx['size_medium']->id, $fx['top_pepperoni']->id],
            ]);
        $cookie = cartCookieFrom($first);

        $this->actingAs($user)
            ->withCookie(CartManager::COOKIE_NAME, $cookie)
            ->post("http://{$r->subdomain}.plateful.test/orders", [
                'customer_name' => 'Dana',
                'customer_email' => 'dana@example.test',
                'type' => 'pickup',
            ]);
        payLatestCheckout();

        // Clear tenant state between requests so the next fixture's tenant is fresh.
        app(CurrentTenant::class)->clear();
        // Carts are per-request and per-tenant; nothing else to reset.
    }

    expect(RestaurantCustomer::query()->where('user_id', $user->id)->count())->toBe(2);

    // Counters at marcos count only marcos orders.
    $marcosPivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $a['restaurant']->id)
        ->first();
    expect((int) $marcosPivot->total_orders)->toBe(1);

    $bobsPivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $b['restaurant']->id)
        ->first();
    expect((int) $bobsPivot->total_orders)->toBe(1);
});

test('a customer\'s order history at restaurant Y does not leak into restaurant X', function () {
    // This is the central isolation guarantee of platform-wide accounts.
    $marcos = Restaurant::create([
        'name' => 'M', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
    $bobs = Restaurant::create([
        'name' => 'B', 'subdomain' => 'bobs', 'email' => 'b@b.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);

    $user = User::create([
        'name' => 'Eve', 'email' => 'eve@example.test',
        'password' => Hash::make('password'), 'is_super_admin' => false,
    ]);

    // Place an order at marcos by manually inserting (faster than full HTTP flow).
    Order::create([
        'restaurant_id' => $marcos->id, 'user_id' => $user->id,
        'customer_name' => 'Eve', 'customer_email' => 'eve@example.test',
        'number' => 'MAR-EVE01',
        'status' => OrderStatus::Completed,
        'type' => OrderType::Pickup,
        'subtotal_cents' => 1000, 'tax_cents' => 0, 'tip_cents' => 0,
        'delivery_fee_cents' => 0, 'application_fee_cents' => 0, 'total_cents' => 1000,
        'placed_at' => now(),
    ]);

    // Hitting Bob's order history should show zero orders.
    $resp = $this->actingAs($user)
        ->get("http://{$bobs->subdomain}.plateful.test/account/orders");

    $resp->assertOk()
        ->assertInertia(fn ($p) => $p->has('orders.data', 0));
});
