<?php

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentState;
use App\Enums\RevenueRole;
use App\Models\DeliveryAssignment;
use App\Models\FeeDistribution;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryCancellation;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\MonthlyCommissionCap;
use App\Services\OrderTransition;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Stripe\Refund;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

beforeEach(function (): void {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

/**
 * A captured DoorDash (centrally-billed) delivery order matching plan §1's
 * shape: $30 food, $2.50 tax, $9 courier, $5 tip, under the cap.
 *
 * @param  array<string, mixed>  $overrides
 */
function capturedDoorDashOrder(Restaurant $r, array $overrides = []): Order
{
    $order = makeOrder($r, array_merge([
        'type' => OrderType::Delivery,
        'subtotal_cents' => 3000,
        'tax_cents' => 250,
        'tip_cents' => 500,
        'delivery_fee_cents' => 964,       // Dc = round(900 × 1.04 / 0.971)
        'application_fee_cents' => 1556,   // commission + D + margin + tip
        'platform_commission_cents' => 120,
        'delivery_margin_cents' => 36,     // round(900 × 4/100)
        'courier_fee_cents' => 900,        // D
        'total_cents' => 4714,
    ], $overrides));

    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::DoorDash,
        'external_id' => 'pf-quote-1',
        'status' => DeliveryStatus::DriverAssigned,
        'actual_fee_cents' => 900,
    ]);

    $order->forceFill([
        'payment_state' => PaymentState::Captured,
        'stripe_payment_intent_id' => 'pi_refund_1',
        'delivery_assignment_id' => $assignment->id,
        'status' => OrderStatus::Confirmed,
    ])->save();

    // The earning ledger written at placement: the commission split (one row
    // here, standing in for all role slices) plus the delivery-margin row.
    $user = adminForRestaurant($r, "owner-{$r->subdomain}@m.test");
    FeeDistribution::create([
        'order_id' => $order->id, 'user_id' => $user->id, 'restaurant_id' => $r->id,
        'role' => RevenueRole::Founder->value, 'percent' => 100, 'amount_cents' => 120, 'earned_at' => now(),
    ]);
    FeeDistribution::create([
        'order_id' => $order->id, 'user_id' => $user->id, 'restaurant_id' => $r->id,
        'role' => RevenueRole::DeliveryMargin->value, 'percent' => 100, 'amount_cents' => 36, 'earned_at' => now(),
    ]);

    return $order->fresh();
}

function fakeCourierCancel(DeliveryCancellation $cancellation): void
{
    /** @var MockInterface $dispatcher */
    $dispatcher = Mockery::mock(DeliveryDispatcher::class);
    $dispatcher->shouldReceive('cancel')->andReturn($cancellation);
    app()->instance(DeliveryDispatcher::class, $dispatcher);
}

function spyStripe(): MockInterface
{
    /** @var MockInterface $connect */
    $connect = Mockery::mock(StripeConnectService::class, [app(StripeClient::class)])->makePartial();
    app()->instance(StripeConnectService::class, $connect);

    return $connect;
}

it('pre-pickup: fully refunds food + delivery + tip and reverses the whole fee', function () {
    $r = adminOrderRestaurant('ddrefund1'); // both refund flags default-on in the helper
    $order = capturedDoorDashOrder($r);

    fakeCourierCancel(DeliveryCancellation::fullyRefunded());
    $stripe = spyStripe();
    // A complete refund reverses the whole application fee in one call.
    $stripe->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom(['id' => 're_1']));
    $stripe->shouldNotReceive('refundOrderPartial');

    app(OrderTransition::class)->apply($order->load('restaurant'), OrderStatus::Cancelled, null);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::Cancelled)
        ->and($fresh->refunded_at)->not->toBeNull()
        ->and((int) $fresh->refunded_cents)->toBe(4714)
        ->and((int) $fresh->platform_commission_cents)->toBe(0)
        ->and((int) $fresh->delivery_margin_cents)->toBe(0);

    // Every earning slice for the order is gone.
    expect(FeeDistribution::where('order_id', $order->id)->count())->toBe(0);
});

it('post-pickup: refunds only the food, keeping the delivery Plateful cannot recover', function () {
    $r = adminOrderRestaurant('ddrefund2');
    $order = capturedDoorDashOrder($r);

    // DoorDash kept the courier fee (picked up already).
    fakeCourierCancel(DeliveryCancellation::courierFeeRetained(900));
    $stripe = spyStripe();
    $stripe->shouldReceive('refundOrderPartial')->once()
        ->withArgs(fn (Order $o, int $customer, int $fee) => $customer === 3250 && $fee === 120)
        ->andReturn(Refund::constructFrom(['id' => 're_2']));
    $stripe->shouldNotReceive('refundOrder');

    app(OrderTransition::class)->apply($order->load('restaurant'), OrderStatus::Cancelled, null);

    $fresh = $order->fresh();
    // Partial refund: refunded_at stays null so the retained margin still counts.
    expect($fresh->refunded_at)->toBeNull()
        ->and((int) $fresh->refunded_cents)->toBe(3250)
        ->and((int) $fresh->platform_commission_cents)->toBe(0)
        ->and((int) $fresh->delivery_margin_cents)->toBe(36);

    // Commission slice reversed; the delivery-margin slice remains (earned).
    expect(FeeDistribution::where('order_id', $order->id)->where('role', RevenueRole::Founder->value)->count())->toBe(0)
        ->and(FeeDistribution::where('order_id', $order->id)->where('role', RevenueRole::DeliveryMargin->value)->count())->toBe(1);
});

it('delivery-refunds off: refunds the recoverable delivery but not the food', function () {
    $r = adminOrderRestaurant('ddrefund3');
    $r->forceFill(['delivery_refunds_enabled' => false])->save();
    $order = capturedDoorDashOrder($r);

    fakeCourierCancel(DeliveryCancellation::fullyRefunded()); // pre-pickup, recoverable
    $stripe = spyStripe();
    $stripe->shouldReceive('refundOrderPartial')->once()
        ->withArgs(fn (Order $o, int $customer, int $fee) => $customer === 1464 && $fee === 1436)
        ->andReturn(Refund::constructFrom(['id' => 're_3']));

    app(OrderTransition::class)->apply($order->load('restaurant'), OrderStatus::Cancelled, null);

    $fresh = $order->fresh();
    expect($fresh->refunded_at)->toBeNull()
        ->and((int) $fresh->refunded_cents)->toBe(1464)
        ->and((int) $fresh->platform_commission_cents)->toBe(120) // food commission retained
        ->and((int) $fresh->delivery_margin_cents)->toBe(0);      // margin reversed with the delivery
});

it('refunds fully disabled and post-pickup: cancels but refunds nothing', function () {
    $r = adminOrderRestaurant('ddrefund4');
    $r->forceFill(['delivery_refunds_enabled' => false])->save();
    $order = capturedDoorDashOrder($r);

    fakeCourierCancel(DeliveryCancellation::courierFeeRetained(900));
    $stripe = spyStripe();
    $stripe->shouldNotReceive('refundOrder');
    $stripe->shouldNotReceive('refundOrderPartial');

    app(OrderTransition::class)->apply($order->load('restaurant'), OrderStatus::Cancelled, null);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::Cancelled)
        ->and($fresh->refunded_at)->toBeNull()
        ->and((int) $fresh->refunded_cents)->toBe(0)
        ->and((int) $fresh->platform_commission_cents)->toBe(120);
});

it('drops the refunded commission out of the monthly cap', function () {
    $r = adminOrderRestaurant('ddrefund5');
    $order = capturedDoorDashOrder($r);

    expect(app(MonthlyCommissionCap::class)->monthToDateCents($r->fresh()))->toBe(120);

    fakeCourierCancel(DeliveryCancellation::fullyRefunded());
    $stripe = spyStripe();
    $stripe->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom(['id' => 're_5']));

    app(OrderTransition::class)->apply($order->load('restaurant'), OrderStatus::Cancelled, null);

    // Commission was reversed to 0, so it no longer counts against the cap.
    expect(app(MonthlyCommissionCap::class)->monthToDateCents($r->fresh()))->toBe(0);
});
