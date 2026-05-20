<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Mail\OrderCancelledToCustomer;
use App\Mail\OrderReadyForPickupToCustomer;
use App\Models\OrderEvent;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

test('legal transition succeeds, creates event, updates status', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['status' => OrderStatus::Pending]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'confirmed',
        ])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Confirmed);

    $event = OrderEvent::where('order_id', $order->id)->latest('id')->first();
    expect($event)->not->toBeNull();
    expect($event->from_status)->toBe(OrderStatus::Pending);
    expect($event->to_status)->toBe(OrderStatus::Confirmed);
    expect($event->user_id)->toBe($u->id);
});

test('illegal transition returns 422', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['status' => OrderStatus::Completed]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'confirmed',
        ])
        ->assertStatus(422);

    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('cross-tenant transition is rejected', function () {
    $marcos = adminOrderRestaurant('marcos');
    $bobs = adminOrderRestaurant('bobs');
    $u = adminForRestaurant($marcos);
    $order = makeOrder($bobs);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$bobs->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'confirmed',
        ])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('cancellation note is stored on the order event', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['status' => OrderStatus::Pending]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'cancelled',
            'note' => 'Out of pizza dough',
        ])
        ->assertRedirect();

    $event = OrderEvent::where('order_id', $order->id)->latest('id')->first();
    expect($event->to_status)->toBe(OrderStatus::Cancelled);
    expect($event->note)->toBe('Out of pizza dough');
});

test('customer email is queued when status goes to cancelled', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['status' => OrderStatus::Pending]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'cancelled',
            'note' => 'Sorry',
        ]);

    Mail::assertQueued(
        OrderCancelledToCustomer::class,
        fn ($mail) => $mail->hasTo($order->customer_email)
    );
});

test('customer email is NOT queued for non-cancel non-ready transitions', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['status' => OrderStatus::Pending]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'confirmed',
        ]);

    Mail::assertNothingQueued();
});

test('pickup order transitioning to ready queues the ready email', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['status' => OrderStatus::Preparing, 'type' => OrderType::Pickup]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'ready',
        ]);

    Mail::assertQueued(
        OrderReadyForPickupToCustomer::class,
        fn ($mail) => $mail->hasTo($order->customer_email)
    );
});

test('delivery order transitioning to ready does NOT email customer', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, [
        'status' => OrderStatus::Preparing,
        'type' => OrderType::Delivery,
        'delivery_fee_cents' => 500,
    ]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'ready',
        ]);

    Mail::assertNotQueued(OrderReadyForPickupToCustomer::class);
});

test('multiple transitions produce events in correct order', function () {
    $r = adminOrderRestaurant();
    $u = adminForRestaurant($r);
    $order = makeOrder($r, ['status' => OrderStatus::Pending]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'confirmed',
        ]);
    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'preparing',
        ]);
    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/orders/{$order->number}/transitions", [
            'to_status' => 'ready',
        ]);

    $events = OrderEvent::where('order_id', $order->id)->orderBy('id')->get();
    expect($events)->toHaveCount(3);
    expect($events[0]->to_status)->toBe(OrderStatus::Confirmed);
    expect($events[1]->to_status)->toBe(OrderStatus::Preparing);
    expect($events[2]->to_status)->toBe(OrderStatus::Ready);

    expect($order->fresh()->status)->toBe(OrderStatus::Ready);
});
