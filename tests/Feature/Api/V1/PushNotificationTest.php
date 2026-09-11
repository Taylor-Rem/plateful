<?php

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\DeliveryAssignment;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use App\Services\OrderTransition;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiCheckoutHelpers.php';

const PUSH_TOKEN = 'ExponentPushToken[push-test]';

beforeEach(function () {
    Mail::fake();
});

/**
 * A paid pickup order owned by a customer with one registered phone.
 *
 * @return array{0: Order, 1: User}
 */
function pushableOrder(array $userAttributes = []): array
{
    $f = cartFixture();
    [, $token] = addPepViaApi($f);
    fakePaymentIntents('succeeded');
    $pendingId = test()->withHeader('X-Cart-Token', $token)
        ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
        ->json('data.pendingCheckoutId');
    test()->withHeader('X-Cart-Token', $token)
        ->postJson(apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm")
        ->assertCreated();
    test()->flushHeaders();

    $user = User::factory()->create($userAttributes);
    DeviceToken::create(['user_id' => $user->id, 'provider' => 'expo', 'platform' => 'ios', 'token' => PUSH_TOKEN]);

    $order = Order::withoutTenantScope()->latest('id')->firstOrFail();
    $order->forceFill(['user_id' => $user->id])->save();

    return [$order->fresh(), $user];
}

function fakeExpo(array $tickets = [['status' => 'ok', 'id' => 'ticket-1']]): void
{
    Http::fake([ExpoPushChannel::ENDPOINT => Http::response(['data' => $tickets])]);
}

function expoPushes(): array
{
    $pushes = [];
    Http::assertSent(function (ClientRequest $request) use (&$pushes): bool {
        if ($request->url() === ExpoPushChannel::ENDPOINT) {
            $pushes[] = $request->data();
        }

        return true;
    });

    return array_merge(...$pushes ?: [[]]);
}

test('confirming an order pushes a milestone to the customer\'s phone', function () {
    [$order] = pushableOrder();
    fakeExpo();

    app(OrderTransition::class)->apply($order, OrderStatus::Confirmed, null);

    $pushes = expoPushes();
    expect($pushes)->toHaveCount(1)
        ->and($pushes[0]['to'])->toBe(PUSH_TOKEN)
        ->and($pushes[0]['title'])->toBe("Order {$order->number} confirmed")
        ->and($pushes[0]['data'])->toBe(['type' => 'order', 'orderNumber' => $order->number, 'restaurant' => 'marcos', 'status' => 'confirmed']);
});

test('internal steps stay quiet; ready-for-pickup, completed, and cancelled push', function () {
    [$order] = pushableOrder();
    fakeExpo();
    $transition = app(OrderTransition::class);

    $transition->apply($order, OrderStatus::Confirmed, null);
    $transition->apply($order, OrderStatus::Preparing, null);
    $transition->apply($order, OrderStatus::Ready, null);
    $transition->apply($order, OrderStatus::Completed, null);

    $titles = array_column(expoPushes(), 'title');
    expect($titles)->toBe([
        "Order {$order->number} confirmed",
        "Order {$order->number} is ready",
        "Order {$order->number} complete",
    ]);
});

test('courier pickup and delivery push through the assignment, whoever updates it', function () {
    [$order] = pushableOrder();
    fakeExpo();

    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::Uber,
        'external_id' => 'pf-1',
        'status' => DeliveryStatus::DriverAssigned,
        'driver_name' => 'Dana',
    ]);
    $order->forceFill(['delivery_assignment_id' => $assignment->id])->save();

    $assignment->update(['status' => DeliveryStatus::PickedUp]);
    $assignment->update(['dropoff_eta_at' => now()->addMinutes(10)]);   // no status change: silent
    $assignment->update(['status' => DeliveryStatus::Delivered]);

    $pushes = expoPushes();
    expect(array_column($pushes, 'title'))->toBe(['Your order is on its way', "Order {$order->number} delivered"])
        ->and($pushes[0]['body'])->toBe("Dana picked up order {$order->number} from Marco's.");
});

test('a customer who switched push off hears nothing', function () {
    [$order] = pushableOrder(['push_order_updates' => false]);
    fakeExpo();

    app(OrderTransition::class)->apply($order, OrderStatus::Confirmed, null);

    Http::assertNothingSent();
});

test('a guest order pushes nothing', function () {
    [$order] = pushableOrder();
    $order->forceFill(['user_id' => null])->save();
    fakeExpo();

    app(OrderTransition::class)->apply($order->fresh(), OrderStatus::Confirmed, null);

    Http::assertNothingSent();
});

test('a token Expo reports as unregistered is pruned', function () {
    [$order] = pushableOrder();
    fakeExpo([['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']]]);

    app(OrderTransition::class)->apply($order, OrderStatus::Confirmed, null);

    expect(DeviceToken::count())->toBe(0);
});
