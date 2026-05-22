<?php

use App\Enums\OrderStatus;
use App\Models\Restaurant;
use App\Models\User;

require_once __DIR__.'/AdminOrderTestHelpers.php';

const KITCHEN_ADMIN_BASE = 'http://admin.plateful.test';

function kitchenRestaurant(string $sub = 'kitchen'): Restaurant
{
    return adminOrderRestaurant($sub);
}

function kitchenStaff(Restaurant $r, string $role = 'staff'): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

test('kitchen board renders for restaurant member', function () {
    $r = kitchenRestaurant();
    $staff = kitchenStaff($r);

    $this->actingAs($staff)
        ->get(KITCHEN_ADMIN_BASE."/{$r->subdomain}/kitchen")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Admin/TenantAdmin/Kitchen'));
});

test('kitchen board only includes confirmed, preparing, and ready orders', function () {
    $r = kitchenRestaurant();
    $staff = kitchenStaff($r);

    makeOrder($r, ['status' => OrderStatus::Pending]);
    makeOrder($r, ['status' => OrderStatus::Confirmed]);
    makeOrder($r, ['status' => OrderStatus::Preparing]);
    makeOrder($r, ['status' => OrderStatus::Ready]);
    makeOrder($r, ['status' => OrderStatus::Completed]);
    makeOrder($r, ['status' => OrderStatus::Cancelled]);

    $this->actingAs($staff)
        ->get(KITCHEN_ADMIN_BASE."/{$r->subdomain}/kitchen")
        ->assertOk()
        ->assertInertia(function ($page) {
            $orders = $page->toArray()['props']['orders'];
            expect($orders)->toHaveCount(3);
            $statuses = array_map(fn ($o) => $o['status'], $orders);
            sort($statuses);
            expect($statuses)->toBe(['confirmed', 'preparing', 'ready']);

            return $page;
        });
});

test('staff can advance an order from confirmed to preparing via the existing endpoint', function () {
    $r = kitchenRestaurant();
    $staff = kitchenStaff($r);
    $order = makeOrder($r, ['status' => OrderStatus::Confirmed]);

    $this->actingAs($staff)
        ->post(KITCHEN_ADMIN_BASE."/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'preparing',
        ])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
});

test('unauthenticated requests to the kitchen board are rejected', function () {
    $r = kitchenRestaurant();

    $this->get(KITCHEN_ADMIN_BASE."/{$r->subdomain}/kitchen")
        ->assertRedirect();
});

test('user without restaurant membership cannot view the kitchen', function () {
    $r = kitchenRestaurant();
    $outsider = User::factory()->admin()->create();

    $this->actingAs($outsider)
        ->get(KITCHEN_ADMIN_BASE."/{$r->subdomain}/kitchen")
        ->assertForbidden();
});
