<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The customer's standing at one restaurant: points and order history
 * totals. Read-only until §10 decides how points are redeemed.
 */
#[TypeScript]
class WalletEntryData extends Data
{
    public function __construct(
        public RestaurantSummaryData $restaurant,
        public int $loyaltyPoints,
        public int $pointsPerDollar,
        public int $totalOrders,
        public int $totalSpentCents,
        public ?string $lastOrderedAt,
    ) {}
}
