<?php

use App\Models\OrderEvent;
use App\Models\User;

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('admin can view an order they have access to', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/Orders/Show')
            ->where('order.number', $order->number));
});

test('order detail includes events ordered newest first', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r);

    OrderEvent::create([
        'order_id' => $order->id,
        'from_status' => null,
        'to_status' => 'pending',
        'occurred_at' => now()->subMinutes(10),
        'user_id' => null,
    ]);
    OrderEvent::create([
        'order_id' => $order->id,
        'from_status' => 'pending',
        'to_status' => 'confirmed',
        'occurred_at' => now()->subMinutes(2),
        'user_id' => $u->id,
    ]);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}")
        ->assertInertia(fn ($p) => $p
            ->has('events', 2)
            ->where('events.0.toStatus', 'confirmed')
            ->where('events.1.toStatus', 'pending'));
});

test('cross-tenant order detail returns 404 or forbidden', function () {
    $marcos = adminOrderRestaurant('marcos');
    $bobs = adminOrderRestaurant('bobs');
    $u = adminForRestaurant($marcos);
    $order = makeOrder($bobs);

    // Going via the bobs URL: the admin lacks access → 403
    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$bobs->subdomain}/orders/{$order->number}")
        ->assertForbidden();
});

test('customer (non-admin) cannot access admin order detail', function () {
    $r = adminOrderRestaurant();
    $order = makeOrder($r);
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}")
        ->assertForbidden();
});
