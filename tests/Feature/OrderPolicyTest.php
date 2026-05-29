<?php

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\OrderPolicy;

function opRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'R',
        'subdomain' => 'op-'.uniqid(),
        'email' => 'r@r.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function opOrder(Restaurant $r, ?User $owner, ?string $token = 'tok-abc'): Order
{
    return Order::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'user_id' => $owner?->id,
        'number' => 'ORD-'.uniqid(),
        'status' => 'pending',
        'type' => 'pickup',
        'subtotal_cents' => 100, 'tax_cents' => 0, 'tip_cents' => 0,
        'delivery_fee_cents' => 0, 'application_fee_cents' => 0, 'total_cents' => 100,
        'confirmation_token' => $token,
    ]);
}

test('owner can view their order', function () {
    $r = opRestaurant();
    $owner = User::factory()->create();
    $order = opOrder($r, $owner);
    expect((new OrderPolicy)->view($owner, $order))->toBeTrue();
});

test('a different user cannot view someone else\'s order without the cookie token', function () {
    $r = opRestaurant();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = opOrder($r, $owner);
    expect((new OrderPolicy)->view($other, $order))->toBeFalse()
        ->and((new OrderPolicy)->view($other, $order, 'wrong-token'))->toBeFalse();
});

test('guest can view via the matching confirmation cookie token', function () {
    $r = opRestaurant();
    $order = opOrder($r, null, 'tok-xyz');
    expect((new OrderPolicy)->view(null, $order, 'tok-xyz'))->toBeTrue()
        ->and((new OrderPolicy)->view(null, $order, 'wrong'))->toBeFalse()
        ->and((new OrderPolicy)->view(null, $order, null))->toBeFalse();
});

test('null confirmation_token is never matched by an empty cookie', function () {
    $r = opRestaurant();
    $order = opOrder($r, null, null);
    expect((new OrderPolicy)->view(null, $order, null))->toBeFalse()
        ->and((new OrderPolicy)->view(null, $order, ''))->toBeFalse();
});
