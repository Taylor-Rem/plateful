<?php

use App\Enums\OrderStatus;
use App\Models\OrderEvent;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../ApiHelpers.php';
require_once __DIR__.'/../../../Admin/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

test('orders list newest first with status counts and filters', function () {
    $restaurant = adminOrderRestaurant();
    $older = makeOrder($restaurant, ['number' => 'OLD-00001', 'placed_at' => now()->subHour(), 'status' => OrderStatus::Completed]);
    $newer = makeOrder($restaurant, ['number' => 'NEW-00002', 'customer_name' => 'Zed Zebra']);
    makeOrder(Restaurant::factory()->create(), ['number' => 'ELSE-0003']);

    $this->withToken(apiKeyFor($restaurant));

    $this->getJson(operatorUrl($restaurant, 'orders'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', 'NEW-00002')
        ->assertJsonPath('data.1.number', 'OLD-00001')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.statusCounts.pending', 1)
        ->assertJsonPath('meta.statusCounts.completed', 1);

    $this->getJson(operatorUrl($restaurant, 'orders?status[]=completed'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $older->id);

    $this->getJson(operatorUrl($restaurant, 'orders?search=zebra'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newer->id);

    $this->getJson(operatorUrl($restaurant, 'orders?per_page=1&page=2'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', 'OLD-00001')
        ->assertJsonPath('meta.lastPage', 2);
});

test('an order shows items, payment state and its timeline', function () {
    $restaurant = adminOrderRestaurant();
    $admin = adminForRestaurant($restaurant);
    $order = makeOrder($restaurant, ['number' => 'ABC-12345', 'payment_state' => 'captured']);
    OrderEvent::create([
        'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'confirmed',
        'occurred_at' => now(), 'user_id' => $admin->id, 'note' => 'On it',
    ]);

    $this->withToken(apiKeyFor($restaurant))->getJson(operatorUrl($restaurant, 'orders/ABC-12345'))
        ->assertOk()
        ->assertJsonPath('data.order.number', 'ABC-12345')
        ->assertJsonPath('data.order.items.0.name', 'Sample item')
        ->assertJsonPath('data.paymentState', 'captured')
        ->assertJsonPath('data.events.0.toStatus', 'confirmed')
        ->assertJsonPath('data.events.0.userName', 'Owner')
        ->assertJsonPath('data.events.0.note', 'On it');
});

test('an order number from another restaurant is a 404 inside this one', function () {
    $restaurant = adminOrderRestaurant();
    $elsewhere = makeOrder(Restaurant::factory()->create(), ['number' => 'ELSE-0001']);

    $this->withToken(apiKeyFor($restaurant))
        ->getJson(operatorUrl($restaurant, 'orders/ELSE-0001'))
        ->assertNotFound();

    expect($elsewhere->fresh())->not->toBeNull();
});

test('a person transitioning an order is recorded on the event; a key is not', function () {
    $restaurant = adminOrderRestaurant();
    $admin = adminForRestaurant($restaurant);
    $order = makeOrder($restaurant);

    $this->withToken(operatorTokenFor($admin))
        ->postJson(operatorUrl($restaurant, "orders/{$order->number}/transition"), ['to_status' => 'confirmed', 'note' => 'Go'])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'confirmed')
        ->assertJsonPath('data.events.0.userName', 'Owner')
        ->assertJsonPath('data.events.0.note', 'Go');
    forgetApiGuards();

    $this->withToken(apiKeyFor($restaurant))
        ->postJson(operatorUrl($restaurant, "orders/{$order->number}/transition"), ['to_status' => 'preparing'])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'preparing')
        ->assertJsonPath('data.events.0.userName', null);

    expect($order->fresh()->status)->toBe(OrderStatus::Preparing)
        ->and(OrderEvent::query()->where('order_id', $order->id)->count())->toBe(2);
});

test('an illegal transition is a 422 and validation guards the target status', function () {
    $restaurant = adminOrderRestaurant();
    $order = makeOrder($restaurant, ['status' => OrderStatus::Completed]);

    $this->withToken(apiKeyFor($restaurant));

    $this->postJson(operatorUrl($restaurant, "orders/{$order->number}/transition"), ['to_status' => 'confirmed'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot transition order from completed to confirmed.');

    $this->postJson(operatorUrl($restaurant, "orders/{$order->number}/transition"), ['to_status' => 'pending'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_status']);
});

test('the kitchen board lists active orders oldest first and honours since', function () {
    $restaurant = adminOrderRestaurant();
    $ready = makeOrder($restaurant, ['number' => 'A-1', 'status' => OrderStatus::Ready, 'placed_at' => now()->subMinutes(30)]);
    $pending = makeOrder($restaurant, ['number' => 'B-2', 'status' => OrderStatus::Pending, 'placed_at' => now()->subMinutes(5)]);
    makeOrder($restaurant, ['number' => 'C-3', 'status' => OrderStatus::Completed]);
    makeOrder($restaurant, ['number' => 'D-4', 'status' => OrderStatus::Cancelled]);

    $this->withToken(apiKeyFor($restaurant));

    $this->getJson(operatorUrl($restaurant, 'kitchen'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', 'A-1')
        ->assertJsonPath('data.1.number', 'B-2')
        ->assertJsonStructure(['meta' => ['asOf']]);

    $ready->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $pending->forceFill(['updated_at' => now()])->saveQuietly();

    $this->getJson(operatorUrl($restaurant, 'kitchen?since='.urlencode(now()->subHour()->toIso8601String())))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', 'B-2');
});
