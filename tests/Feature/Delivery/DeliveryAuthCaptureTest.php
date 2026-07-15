<?php

use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryMode;
use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Jobs\ExpireAuthorizedDelivery;
use App\Jobs\PushOrderToPos;
use App\Mail\OrderCancelledToCustomer;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryIntegration;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PosIntegration;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliverySettlement;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();

    $this->restaurant = adminOrderRestaurant('capco');
    $this->restaurant->forceFill([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_fee_strategy' => DeliveryFeeStrategy::PassThrough,
        'phone' => '5551234567',
    ])->save();

    DeliveryIntegration::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'customer_id' => 'cust_cap',
    ]);
});

/**
 * A paid delivery order sitting in the authorized state, with a courier
 * search underway.
 */
function authorizedOrder(?DeliveryStatus $assignmentStatus = DeliveryStatus::Pending): Order
{
    $order = makeOrder(test()->restaurant);
    $order->forceFill([
        'type' => 'delivery',
        'payment_state' => PaymentState::Authorized,
        'authorized_at' => now(),
        'stripe_payment_intent_id' => 'pi_auth_1',
        'status' => OrderStatus::Pending,
    ])->save();

    if ($assignmentStatus !== null) {
        $assignment = DeliveryAssignment::create([
            'order_id' => $order->id,
            'provider' => DeliveryProviderName::Uber,
            'external_id' => 'del_auth_1',
            'status' => $assignmentStatus,
        ]);
        $order->forceFill(['delivery_assignment_id' => $assignment->id])->save();
    }

    return $order->fresh();
}

function fakeStripe(): MockInterface
{
    $mock = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $mock);

    return $mock;
}

it('captures and releases the kitchen ticket once a courier is confirmed', function () {
    Bus::fake([PushOrderToPos::class]);
    $order = authorizedOrder();
    PosIntegration::factory()->create(['restaurant_id' => test()->restaurant->id]);

    $stripe = fakeStripe();
    $stripe->shouldReceive('capturePayment')->once()->with(Mockery::on(
        fn (Order $o): bool => $o->id === $order->id
    ));

    app(DeliverySettlement::class)->onCourierConfirmed($order);

    $fresh = $order->fresh();
    expect($fresh->payment_state)->toBe(PaymentState::Captured);
    expect($fresh->captured_at)->not->toBeNull();

    // The ticket OrderPlacement held back is released by the same signal that
    // captures the money: the delivery is real now.
    Bus::assertDispatched(PushOrderToPos::class);
});

it('leaves the order authorized when the capture itself fails', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    $stripe->shouldReceive('capturePayment')->once()->andThrow(new RuntimeException('card declined'));

    app(DeliverySettlement::class)->onCourierConfirmed($order);

    // Flipping to Captured here would mean believing we hold money we never
    // took. Staying Authorized lets the deadline job resolve it.
    expect($order->fresh()->payment_state)->toBe(PaymentState::Authorized);
});

it('voids the hold and cancels when no courier can be found', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    $stripe->shouldReceive('voidPayment')->once();

    app(DeliverySettlement::class)->onCourierUnavailable($order, 'no couriers available');

    $fresh = $order->fresh();
    expect($fresh->payment_state)->toBe(PaymentState::Voided);
    expect($fresh->voided_at)->not->toBeNull();
    expect($fresh->status)->toBe(OrderStatus::Cancelled);

    // Never charged, so nothing to refund — the customer sees a pending hold
    // drop off, not a charge followed by a refund. A refund here would mean we
    // had taken money we promised not to take.
    expect($fresh->refunded_at)->toBeNull();
    expect((int) $fresh->refunded_cents)->toBe(0);

    Mail::assertQueued(OrderCancelledToCustomer::class);
});

it('still cancels the order when releasing the hold fails, and says so loudly', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    $stripe->shouldReceive('voidPayment')->once()->andThrow(new RuntimeException('stripe down'));

    app(DeliverySettlement::class)->onCourierUnavailable($order, 'no couriers available');

    $fresh = $order->fresh();
    // An uncancelled order with no courier is worse than a hold we failed to
    // release — the hold expires on its own, the order would not.
    expect($fresh->status)->toBe(OrderStatus::Cancelled);
    expect($fresh->payment_state)->toBe(PaymentState::Authorized);

    $note = OrderEvent::query()->where('order_id', $order->id)->latest('id')->first()->note;
    expect($note)->toContain('RELEASING THE PAYMENT HOLD FAILED');
});

it('never settles the same order twice', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    // Uber retries webhooks and the deadline job races them by design, so both
    // entry points must be safe to reach more than once.
    $stripe->shouldReceive('capturePayment')->once();

    $settlement = app(DeliverySettlement::class);
    $settlement->onCourierConfirmed($order);
    $settlement->onCourierConfirmed($order->fresh());
});

it('will not void an order that was already captured', function () {
    $order = authorizedOrder();
    $order->forceFill(['payment_state' => PaymentState::Captured])->save();

    $stripe = fakeStripe();
    $stripe->shouldNotReceive('voidPayment');

    app(DeliverySettlement::class)->onCourierUnavailable($order->fresh(), 'late webhook');

    expect($order->fresh()->status)->not->toBe(OrderStatus::Cancelled);
});

it('gives up and voids when the courier deadline passes', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    $stripe->shouldReceive('voidPayment')->once();

    // The failure mode worth designing for: a search that hangs, stranding the
    // order with no ticket and a hold on the customer's card.
    (new ExpireAuthorizedDelivery($order->id))->handle(
        app(DeliverySettlement::class),
        app(DeliveryDispatcher::class),
    );

    $fresh = $order->fresh();
    expect($fresh->payment_state)->toBe(PaymentState::Voided);
    expect($fresh->status)->toBe(OrderStatus::Cancelled);
});

it('does nothing at the deadline when the courier already arrived', function () {
    $order = authorizedOrder();
    $order->forceFill(['payment_state' => PaymentState::Captured])->save();

    $stripe = fakeStripe();
    $stripe->shouldNotReceive('voidPayment');

    // The webhook beat the deadline — the happy path.
    (new ExpireAuthorizedDelivery($order->id))->handle(
        app(DeliverySettlement::class),
        app(DeliveryDispatcher::class),
    );

    expect($order->fresh()->status)->not->toBe(OrderStatus::Cancelled);
});

it('polls the provider at the deadline and captures if a courier exists after all', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    // The webhook never arrived — no signing key, or the endpoint was down
    // while Uber retried. Without this poll EVERY delivery for such a
    // restaurant would silently void at the deadline with a courier en route.
    $stripe->shouldReceive('capturePayment')->once();
    $stripe->shouldNotReceive('voidPayment');

    $dispatcher = Mockery::mock(DeliveryDispatcher::class);
    $dispatcher->shouldReceive('status')->once()->andReturnUsing(function (DeliveryAssignment $a) {
        $a->forceFill(['status' => DeliveryStatus::DriverAssigned])->save();

        return $a;
    });
    $dispatcher->shouldNotReceive('cancel');

    (new ExpireAuthorizedDelivery($order->id))->handle(app(DeliverySettlement::class), $dispatcher);

    expect($order->fresh()->payment_state)->toBe(PaymentState::Captured);
});

it('fails closed when the provider is unreachable at the deadline', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    $stripe->shouldReceive('voidPayment')->once();

    $dispatcher = Mockery::mock(DeliveryDispatcher::class);
    // An unreachable provider is not proof a courier exists. Releasing the
    // customer's money is the safe direction to be wrong in.
    $dispatcher->shouldReceive('status')->andThrow(new RuntimeException('uber down'));
    $dispatcher->shouldReceive('cancel');

    (new ExpireAuthorizedDelivery($order->id))->handle(app(DeliverySettlement::class), $dispatcher);

    expect($order->fresh()->payment_state)->toBe(PaymentState::Voided);
});

it('tells the provider to stop looking before releasing the hold', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    $stripe->shouldReceive('voidPayment')->once();

    $dispatcher = Mockery::mock(DeliveryDispatcher::class);
    // Otherwise a courier could still turn up at a kitchen for an order that
    // no longer exists.
    $dispatcher->shouldReceive('cancel')->once();

    (new ExpireAuthorizedDelivery($order->id))->handle(app(DeliverySettlement::class), $dispatcher);
});

it('releases the hold anyway when the provider cancel fails', function () {
    $order = authorizedOrder();

    $stripe = fakeStripe();
    $stripe->shouldReceive('voidPayment')->once();

    $dispatcher = Mockery::mock(DeliveryDispatcher::class);
    $dispatcher->shouldReceive('cancel')->andThrow(new RuntimeException('uber down'));

    // Uber being unreachable must not strand a hold on a customer's card.
    (new ExpireAuthorizedDelivery($order->id))->handle(app(DeliverySettlement::class), $dispatcher);

    expect($order->fresh()->payment_state)->toBe(PaymentState::Voided);
});
