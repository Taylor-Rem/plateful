<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Mail\OrderCancelledToCustomer;
use App\Models\Order;
use App\Services\OrderTransition;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
    $this->restaurant = adminOrderRestaurant('cancelco');
});

function stripeSpy(): MockInterface
{
    $mock = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $mock);

    return $mock;
}

function orderInState(PaymentState $state): Order
{
    $order = makeOrder(test()->restaurant);
    $order->forceFill([
        'payment_state' => $state,
        'stripe_payment_intent_id' => 'pi_cancel_1',
        'status' => OrderStatus::Pending,
    ])->save();

    return $order->fresh();
}

it('voids rather than refunds when cancelling an order that is only authorized', function () {
    $order = orderInState(PaymentState::Authorized);

    $stripe = stripeSpy();
    // Stripe REJECTS a refund against an uncaptured intent. Without this split
    // an owner cancelling a delivery before its courier was found would hit an
    // error, the refund would silently fail, and the hold would sit on the
    // customer's card until the bank dropped it.
    $stripe->shouldReceive('voidPayment')->once();
    $stripe->shouldNotReceive('refundOrder');

    app(OrderTransition::class)->apply($order, OrderStatus::Cancelled, null, 'kitchen closed');

    $fresh = $order->fresh();
    expect($fresh->payment_state)->toBe(PaymentState::Voided);
    expect($fresh->voided_at)->not->toBeNull();
    // Nothing was ever charged, so nothing was refunded.
    expect($fresh->refunded_at)->toBeNull();

    Mail::assertQueued(OrderCancelledToCustomer::class);
});

it('still refunds when cancelling an order whose money was actually taken', function () {
    $order = orderInState(PaymentState::Captured);

    $stripe = stripeSpy();
    $stripe->shouldReceive('refundOrder')->once();
    $stripe->shouldNotReceive('voidPayment');

    app(OrderTransition::class)->apply($order, OrderStatus::Cancelled, null, 'out of stock');

    $fresh = $order->fresh();
    expect($fresh->refunded_at)->not->toBeNull();
    expect((int) $fresh->refunded_cents)->toBe((int) $order->total_cents);
});

it('does not double-void an order whose hold was already released', function () {
    $order = orderInState(PaymentState::Voided);

    $stripe = stripeSpy();
    $stripe->shouldNotReceive('voidPayment');
    $stripe->shouldNotReceive('refundOrder');

    app(OrderTransition::class)->apply($order, OrderStatus::Cancelled, null, null);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('cancels the order even when releasing the hold throws', function () {
    $order = orderInState(PaymentState::Authorized);

    $stripe = stripeSpy();
    $stripe->shouldReceive('voidPayment')->once()->andThrow(new RuntimeException('stripe down'));

    app(OrderTransition::class)->apply($order, OrderStatus::Cancelled, null, null);

    // Best-effort: a Stripe failure must not block the cancel.
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});
