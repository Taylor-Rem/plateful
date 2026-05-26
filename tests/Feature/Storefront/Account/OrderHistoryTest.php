<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function ohR(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => ucfirst($sub),
        'subdomain' => $sub,
        'email' => "$sub@example.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function ohU(Restaurant $r, string $email = 'c@c.test'): User
{
    return User::create([
        'name' => 'C', 'email' => $email,
        'password' => Hash::make('password'),
        'is_super_admin' => false,
    ]);
}

function ohOrder(Restaurant $r, ?User $user, array $overrides = []): Order
{
    return Order::create(array_merge([
        'restaurant_id' => $r->id,
        'user_id' => $user?->id,
        'customer_name' => 'C',
        'customer_email' => 'c@c.test',
        'number' => 'TST-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'status' => OrderStatus::Pending,
        'type' => OrderType::Pickup,
        'subtotal_cents' => 1000,
        'tax_cents' => 0,
        'tip_cents' => 0,
        'delivery_fee_cents' => 0,
        'application_fee_cents' => 0,
        'total_cents' => 1000,
        'placed_at' => now(),
    ], $overrides));
}

test('customer sees only their own orders', function () {
    $r = ohR();
    $alice = ohU($r, 'alice@a.test');
    $bob = ohU($r, 'bob@b.test');

    ohOrder($r, $alice, ['number' => 'MAR-AAAAA']);
    ohOrder($r, $bob, ['number' => 'MAR-BBBBB']);

    $resp = $this->actingAs($alice)
        ->get("http://{$r->subdomain}.plateful.test/account/orders");

    $resp->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Storefront/Account/Orders')
            ->has('orders.data', 1)
            ->where('orders.data.0.number', 'MAR-AAAAA'));
});

test('orders from another tenant are not visible', function () {
    $marcos = ohR('marcos');
    $bobs = ohR('bobs');
    $alice = ohU($marcos, 'alice@a.test');

    ohOrder($bobs, null, ['number' => 'BOB-XXXXX']);
    ohOrder($marcos, $alice, ['number' => 'MAR-OWN1']);

    $resp = $this->actingAs($alice)
        ->get("http://{$marcos->subdomain}.plateful.test/account/orders");

    $resp->assertOk()
        ->assertInertia(fn ($p) => $p->has('orders.data', 1)
            ->where('orders.data.0.number', 'MAR-OWN1'));
});

test('awarded loyalty points appear in DTO', function () {
    $r = ohR();
    $u = ohU($r);
    ohOrder($r, $u, ['awarded_loyalty_points' => 12, 'status' => OrderStatus::Completed]);

    $resp = $this->actingAs($u)
        ->get("http://{$r->subdomain}.plateful.test/account/orders");

    $resp->assertInertia(fn ($p) => $p
        ->where('orders.data.0.awardedLoyaltyPoints', 12));
});

test('pagination respects 15 per page', function () {
    $r = ohR();
    $u = ohU($r);
    for ($i = 0; $i < 18; $i++) {
        ohOrder($r, $u);
    }

    $resp = $this->actingAs($u)
        ->get("http://{$r->subdomain}.plateful.test/account/orders");

    $resp->assertInertia(fn ($p) => $p
        ->has('orders.data', 15)
        ->where('orders.last_page', 2));
});
