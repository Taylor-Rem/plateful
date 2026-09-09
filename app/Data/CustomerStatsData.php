<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CustomerStatsData extends Data
{
    public function __construct(
        /** Null when no identified orders exist — the tile renders an em dash. */
        public ?float $repeatOrderPct,
        /** Null when identified revenue is zero — the tile renders an em dash. */
        public ?float $repeatRevenuePct,
        /** Null when no customers (non-deleted users) have ordered. One decimal. */
        public ?float $avgOrdersPerCustomer,
        /** Null when fewer than two consecutive same-user order pairs exist. */
        public ?float $medianDaysBetweenOrders,
        /** Customers on the pivot with a live (non-soft-deleted) user account. */
        public int $identifiedCustomers,
        /** Non-cancelled orders placed by signed-in customers, all time. */
        public int $identifiedOrders,
        /** Last 12 restaurant-local calendar months, oldest first; zero months included. */
        #[DataCollectionOf(CustomerStatsMonthData::class)]
        /** @var array<int, CustomerStatsMonthData> */
        public array $monthly,
    ) {}
}
