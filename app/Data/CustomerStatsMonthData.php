<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CustomerStatsMonthData extends Data
{
    public function __construct(
        /** Restaurant-local calendar month, `Y-m` (e.g. "2026-08"). */
        public string $month,
        /** Revenue from identified customers' first orders here. */
        public int $newCents,
        /** Revenue from identified customers' repeat orders here. */
        public int $returningCents,
        /** Revenue from guest-checkout orders (no account). */
        public int $guestCents,
    ) {}
}
