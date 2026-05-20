<?php

use App\Enums\OrderStatus;

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('admin with restaurant access sees orders for their restaurant', function () {
    $r = adminOrderRestaurant('marcos');
    $u = adminForRestaurant($r);

    makeOrder($r, ['customer_name' => 'Visible One']);
    makeOrder($r, ['customer_name' => 'Visible Two']);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/Orders/Index')
            ->has('orders', 2)
        );
});

test('admin cannot see another restaurants orders', function () {
    $marcos = adminOrderRestaurant('marcos');
    $bobs = adminOrderRestaurant('bobs');
    $u = adminForRestaurant($marcos);

    makeOrder($bobs, ['customer_name' => 'Bob Customer']);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$bobs->subdomain}/orders")
        ->assertForbidden();
});

test('filter by status only returns matching orders', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);

    makeOrder($r, ['status' => OrderStatus::Pending]);
    makeOrder($r, ['status' => OrderStatus::Confirmed]);
    makeOrder($r, ['status' => OrderStatus::Confirmed]);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders?status=confirmed")
        ->assertInertia(fn ($p) => $p->has('orders', 2));
});

test('search by order number returns matches', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);

    makeOrder($r, ['number' => 'MAR-AAAAA']);
    makeOrder($r, ['number' => 'MAR-BBBBB']);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders?search=AAAA")
        ->assertInertia(fn ($p) => $p->has('orders', 1)
            ->where('orders.0.number', 'MAR-AAAAA'));
});

test('search by customer name returns matches', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);

    makeOrder($r, ['customer_name' => 'Alice Apple']);
    makeOrder($r, ['customer_name' => 'Bob Banana']);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders?search=Apple")
        ->assertInertia(fn ($p) => $p->has('orders', 1)
            ->where('orders.0.customerName', 'Alice Apple'));
});

test('paginates 15 per page', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);

    for ($i = 0; $i < 20; $i++) {
        makeOrder($r);
    }

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders")
        ->assertInertia(fn ($p) => $p->has('orders', 15)
            ->where('pagination.lastPage', 2)
            ->where('pagination.total', 20));

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders?page=2")
        ->assertInertia(fn ($p) => $p->has('orders', 5));
});

test('status counts match actual counts', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);

    makeOrder($r, ['status' => OrderStatus::Pending]);
    makeOrder($r, ['status' => OrderStatus::Pending]);
    makeOrder($r, ['status' => OrderStatus::Confirmed]);
    makeOrder($r, ['status' => OrderStatus::Cancelled]);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders")
        ->assertInertia(fn ($p) => $p
            ->where('statusCounts.pending', 2)
            ->where('statusCounts.confirmed', 1)
            ->where('statusCounts.cancelled', 1)
            ->where('statusCounts.preparing', 0));
});
