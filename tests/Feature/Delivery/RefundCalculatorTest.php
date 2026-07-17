<?php

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Refunds\RefundCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

/**
 * A pickup order carries no courier fee, so the application fee is just the
 * commission and every dollar is restaurant/staff money.
 */
function pickupOrderFor(Restaurant $r): Order
{
    return makeOrder($r, [
        'type' => OrderType::Pickup,
        'subtotal_cents' => 2000,
        'tax_cents' => 160,
        'tip_cents' => 300,
        'delivery_fee_cents' => 0,
        'platform_commission_cents' => 80,
        'application_fee_cents' => 80,
        'total_cents' => 2460,
    ])->load('restaurant');
}

it('fully refunds a pickup order when pickup refunds are enabled', function () {
    $r = adminOrderRestaurant('pickyes'); // helper enables both flags
    $plan = app(RefundCalculator::class)->for(pickupOrderFor($r), null);

    expect($plan->customerRefundCents)->toBe(2460)
        ->and($plan->applicationFeeReversalCents)->toBe(80)
        ->and($plan->reverseCommission)->toBeTrue()
        ->and($plan->reverseMargin)->toBeFalse()
        ->and($plan->isFullRefund)->toBeTrue();
});

it('refunds nothing on a pickup order when pickup refunds are disabled', function () {
    $r = adminOrderRestaurant('pickno');
    $r->forceFill(['pickup_refunds_enabled' => false])->save();

    $plan = app(RefundCalculator::class)->for(pickupOrderFor($r->fresh()), null);

    expect($plan->refundsAnything())->toBeFalse()
        ->and($plan->customerRefundCents)->toBe(0)
        ->and($plan->applicationFeeReversalCents)->toBe(0);
});

it('reads the pickup toggle for pickup and the delivery toggle for delivery independently', function () {
    $r = adminOrderRestaurant('splitpolicy');
    // Pickup refunds off, delivery refunds on — the two are independent.
    $r->forceFill(['pickup_refunds_enabled' => false, 'delivery_refunds_enabled' => true])->save();

    $plan = app(RefundCalculator::class)->for(pickupOrderFor($r->fresh()), null);

    // A pickup order must not be refunded just because delivery refunds are on.
    expect($plan->refundsAnything())->toBeFalse();
});
