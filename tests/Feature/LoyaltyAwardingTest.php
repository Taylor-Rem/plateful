<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Models\LoyaltyPoints;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\OrderTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.loyalty.points_per_dollar' => 1]);
});

function loyaltyR(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => ucfirst($sub), 'subdomain' => $sub, 'email' => "$sub@x.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function loyaltyU(Restaurant $r, string $email = 'c@c.test'): User
{
    return User::create([
        'restaurant_id' => $r->id, 'name' => 'C', 'email' => $email,
        'password' => Hash::make('password'),
        'role' => UserRole::Customer, 'is_super_admin' => false,
    ]);
}

function loyaltyOrder(Restaurant $r, ?User $u, int $subtotal = 2500, OrderStatus $status = OrderStatus::Ready): Order
{
    return Order::create([
        'restaurant_id' => $r->id, 'user_id' => $u?->id,
        'customer_name' => 'C', 'customer_email' => 'c@c.test',
        'number' => 'TST-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'status' => $status, 'type' => OrderType::Pickup,
        'subtotal_cents' => $subtotal, 'tax_cents' => 0, 'tip_cents' => 0,
        'delivery_fee_cents' => 0, 'application_fee_cents' => 0,
        'total_cents' => $subtotal, 'placed_at' => now(),
    ]);
}

test('transitioning to completed awards points based on subtotal dollars', function () {
    $r = loyaltyR();
    $u = loyaltyU($r);
    $order = loyaltyOrder($r, $u, 2599); // $25.99 -> 25 dollars

    app(OrderTransition::class)->apply($order, OrderStatus::Completed, null);

    $fresh = $order->fresh();
    expect($fresh->awarded_loyalty_points)->toBe(25);

    $bal = LoyaltyPoints::where('user_id', $u->id)->where('restaurant_id', $r->id)->value('points');
    expect((int) $bal)->toBe(25);
});

test('guest order does not award points', function () {
    $r = loyaltyR();
    $order = loyaltyOrder($r, null, 2500);

    app(OrderTransition::class)->apply($order, OrderStatus::Completed, null);

    expect($order->fresh()->awarded_loyalty_points)->toBe(0);
    expect(LoyaltyPoints::count())->toBe(0);
});

test('already awarded order does not double-award', function () {
    $r = loyaltyR();
    $u = loyaltyU($r);
    $order = loyaltyOrder($r, $u, 2500);

    // Simulate prior award: manually set + create loyalty row
    $order->awarded_loyalty_points = 25;
    $order->status = OrderStatus::Ready;
    $order->save();
    LoyaltyPoints::create(['user_id' => $u->id, 'restaurant_id' => $r->id, 'points' => 25]);

    // Now transition; the awarder must see existing value and skip
    app(OrderTransition::class)->apply($order, OrderStatus::Completed, null);

    expect($order->fresh()->awarded_loyalty_points)->toBe(25);
    expect((int) LoyaltyPoints::where('user_id', $u->id)->value('points'))->toBe(25);
});

test('completing an order at one tenant does not affect another tenant', function () {
    $marcos = loyaltyR('marcos');
    $bobs = loyaltyR('bobs');
    $alice = loyaltyU($marcos, 'alice@a.test');

    $order = loyaltyOrder($marcos, $alice, 2000);
    app(OrderTransition::class)->apply($order, OrderStatus::Completed, null);

    $marcosBal = LoyaltyPoints::where('user_id', $alice->id)
        ->where('restaurant_id', $marcos->id)->value('points');
    $bobsBal = LoyaltyPoints::where('user_id', $alice->id)
        ->where('restaurant_id', $bobs->id)->value('points');

    expect((int) $marcosBal)->toBe(20);
    expect($bobsBal)->toBeNull();
});

test('subsequent completions increment the same loyalty row', function () {
    $r = loyaltyR();
    $u = loyaltyU($r);

    $o1 = loyaltyOrder($r, $u, 1000);
    app(OrderTransition::class)->apply($o1, OrderStatus::Completed, null);

    $o2 = loyaltyOrder($r, $u, 1500);
    app(OrderTransition::class)->apply($o2, OrderStatus::Completed, null);

    $bal = LoyaltyPoints::where('user_id', $u->id)->where('restaurant_id', $r->id)->value('points');
    expect((int) $bal)->toBe(25);
    expect(LoyaltyPoints::count())->toBe(1);
});
