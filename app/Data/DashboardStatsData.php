<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DashboardStatsData extends Data
{
    public function __construct(
        public int $ordersToday,
        public int $revenueTodayCents,
        /** Null when no captured orders today — the tile renders an em dash. */
        public ?int $avgTicketCents,
        /** Deliberately not day-bounded: it's the needs-action queue. */
        public int $pendingCount,
        /** The restaurant-local date the "today" window covers (Y-m-d). */
        public string $date,
        public string $timezone,
    ) {}
}
