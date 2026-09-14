<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What one restaurant generated for the platform in a month. Money columns
 * exclude refunded orders; `ledgerCents` is the fee_distributions total for
 * the same window and should equal commission + delivery margin.
 */
#[TypeScript]
class RestaurantEarningsData extends Data
{
    public function __construct(
        public int $restaurantId,
        public string $name,
        public string $subdomain,
        public string $status,
        /** `YYYY-MM`. */
        public string $month,
        public int $orders,
        public int $refundedOrders,
        public int $foodSubtotalCents,
        /** Stripe application fee, gross (carries courier passthrough on delivery orders). */
        public int $applicationFeeCents,
        /** Plateful's true commission on food. */
        public int $commissionCents,
        public int $deliveryMarginCents,
        public int $ledgerCents,
        public float $feePercent,
        public int $capCents,
        public bool $capReached,
        /** Commission still chargeable this month; null unless the month is the current one. */
        public ?int $capRemainingCents,
    ) {}
}
