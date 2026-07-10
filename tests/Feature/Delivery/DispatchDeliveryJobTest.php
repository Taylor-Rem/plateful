<?php

use App\Enums\DeliveryMode;
use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\OrderType;
use App\Exceptions\DeliveryProviderException;
use App\Jobs\DispatchDeliveryForOrder;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\SelfDeliveryProvider;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

function deliveryJobOrder(Restaurant $r, array $overrides = []): Order
{
    return makeOrder($r, array_merge([
        'type' => OrderType::Delivery,
        'delivery_address' => [
            'street' => '1 Pine',
            'city' => 'NYC',
            'state' => 'NY',
            'postal_code' => '10002',
            'country' => 'US',
        ],
    ], $overrides));
}

it('creates a delivery assignment and links it to the order', function () {
    $r = adminOrderRestaurant('djhappy');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery,
        'delivery_fee_cents' => 500,
    ]);
    $order = deliveryJobOrder($r);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::Self->value => new SelfDeliveryProvider,
    ]);

    (new DispatchDeliveryForOrder($order->id))->handle($dispatcher);

    $order->refresh();
    expect($order->delivery_assignment_id)->not->toBeNull();

    $event = OrderEvent::query()->where('order_id', $order->id)->latest('id')->first();
    expect($event->note)->toContain('Delivery dispatched via self')
        ->and($event->from_status)->toBe($event->to_status);
});

it('is a no-op for non-delivery orders', function () {
    $r = adminOrderRestaurant('djpickup');
    $r->update(['delivery_enabled' => true, 'delivery_mode' => DeliveryMode::SelfDelivery]);
    $order = makeOrder($r);

    $dispatcher = new DeliveryDispatcher([
        DeliveryProviderName::Self->value => new SelfDeliveryProvider,
    ]);

    (new DispatchDeliveryForOrder($order->id))->handle($dispatcher);

    expect($order->fresh()->delivery_assignment_id)->toBeNull();
    expect(OrderEvent::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('is a no-op when delivery is not configured', function () {
    $r = adminOrderRestaurant('djoff');
    $order = deliveryJobOrder($r);

    (new DispatchDeliveryForOrder($order->id))->handle(new DeliveryDispatcher([]));

    expect($order->fresh()->delivery_assignment_id)->toBeNull();
    expect(OrderEvent::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('is a no-op when the order already has an assignment', function () {
    $r = adminOrderRestaurant('djdone');
    $r->update(['delivery_enabled' => true, 'delivery_mode' => DeliveryMode::SelfDelivery]);
    $order = deliveryJobOrder($r);

    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::Self,
        'status' => DeliveryStatus::Pending,
    ]);
    $order->forceFill(['delivery_assignment_id' => $assignment->id])->save();

    (new DispatchDeliveryForOrder($order->id))->handle(new DeliveryDispatcher([
        DeliveryProviderName::Self->value => new SelfDeliveryProvider,
    ]));

    expect(OrderEvent::query()->where('order_id', $order->id)->count())->toBe(0);
    expect(DeliveryAssignment::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('logs an order event and throws on dispatch failure', function () {
    $r = adminOrderRestaurant('djfail');
    $r->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_provider_priority' => ['doordash'],
    ]);
    $order = deliveryJobOrder($r);

    expect(fn () => (new DispatchDeliveryForOrder($order->id))->handle(new DeliveryDispatcher([])))
        ->toThrow(DeliveryProviderException::class);

    $event = OrderEvent::query()->where('order_id', $order->id)->latest('id')->first();
    expect($event->note)->toContain('Delivery dispatch attempt');
});

it('logs a permanent failure order event when retries are exhausted', function () {
    $r = adminOrderRestaurant('djgaveup');
    $order = deliveryJobOrder($r);

    (new DispatchDeliveryForOrder($order->id))->failed(DeliveryProviderException::driverNotAvailable('doordash'));

    $event = OrderEvent::query()->where('order_id', $order->id)->latest('id')->first();
    expect($event->note)->toContain('Delivery dispatch permanently failed');
});
