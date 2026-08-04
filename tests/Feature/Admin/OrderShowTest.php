<?php

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\OrderType;
use App\Models\DeliveryAssignment;
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

test('order detail exposes the delivery assignment for the merchant panel', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['type' => OrderType::Delivery]);

    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::DoorDash,
        'external_id' => 'pf-abc-123',
        'support_reference' => 'DD-555',
        'status' => DeliveryStatus::PickedUp,
        'provider_status' => 'enroute_to_dropoff',
        'tracking_url' => 'https://doordash.com/tracking/xyz',
        'driver_name' => 'Dana',
        'driver_phone' => '+15551230000',
        'dropoff_eta_at' => now()->addMinutes(20),
    ]);
    $order->forceFill(['delivery_assignment_id' => $assignment->id])->save();

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}")
        ->assertInertia(fn ($p) => $p
            ->where('order.delivery.provider', 'doordash')
            ->where('order.delivery.status', 'picked_up')
            ->where('order.delivery.providerStatus', 'enroute_to_dropoff')
            // The raw provider word wins for display — `picked_up` reads
            // stale while the Dasher is already enroute.
            ->where('order.delivery.statusLabel', 'Enroute to dropoff')
            ->where('order.delivery.supportReference', 'DD-555')
            ->where('order.delivery.externalId', 'pf-abc-123')
            ->where('order.delivery.trackingUrl', 'https://doordash.com/tracking/xyz')
            ->where('order.delivery.driverName', 'Dana')
            ->where('order.delivery.isActive', true));
});

test('order detail carries a null delivery before dispatch', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['type' => OrderType::Delivery]);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}")
        ->assertInertia(fn ($p) => $p->where('order.delivery', null));
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
