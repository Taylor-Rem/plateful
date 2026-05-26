<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\LoyaltyPoints;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function loyR(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => ucfirst($sub), 'subdomain' => $sub, 'email' => "$sub@x.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function loyU(Restaurant $r): User
{
    return User::create([
        'name' => 'C', 'email' => 'c@c.test',
        'password' => Hash::make('password'),
        'is_super_admin' => false,
    ]);
}

test('balance shows zero when no points', function () {
    $r = loyR();
    $u = loyU($r);

    $resp = $this->actingAs($u)->get("http://{$r->subdomain}.plateful.test/account/loyalty");

    $resp->assertOk()->assertInertia(fn ($p) => $p
        ->component('Storefront/Account/Loyalty')
        ->where('balance', 0)
        ->where('recentOrders', []));
});

test('balance shows current value', function () {
    $r = loyR();
    $u = loyU($r);
    LoyaltyPoints::create([
        'user_id' => $u->id, 'restaurant_id' => $r->id, 'points' => 42,
    ]);

    $resp = $this->actingAs($u)->get("http://{$r->subdomain}.plateful.test/account/loyalty");

    $resp->assertInertia(fn ($p) => $p->where('balance', 42));
});

test('recent orders list shows up to 5 completed awarded orders', function () {
    $r = loyR();
    $u = loyU($r);

    for ($i = 0; $i < 7; $i++) {
        Order::create([
            'restaurant_id' => $r->id, 'user_id' => $u->id,
            'customer_name' => 'C', 'customer_email' => 'c@c.test',
            'number' => 'MAR-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'status' => OrderStatus::Completed,
            'type' => OrderType::Pickup,
            'subtotal_cents' => 1000, 'tax_cents' => 0, 'tip_cents' => 0,
            'delivery_fee_cents' => 0, 'application_fee_cents' => 0, 'total_cents' => 1000,
            'placed_at' => now()->subMinutes($i),
            'awarded_loyalty_points' => 10,
        ]);
    }

    $resp = $this->actingAs($u)->get("http://{$r->subdomain}.plateful.test/account/loyalty");

    $resp->assertInertia(fn ($p) => $p->has('recentOrders', 5));
});
