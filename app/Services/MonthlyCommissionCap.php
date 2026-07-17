<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;

/**
 * The monthly commission cap (DoorDash plan §1.3): the most commission Plateful
 * retains from one restaurant in a calendar month.
 *
 * The window is the restaurant's OWN calendar month — an owner reasons about
 * "this month" in their own timezone, so the cap resets at local midnight on the
 * 1st, not UTC midnight. Timestamps are stored UTC, so we compute the local
 * start-of-month and compare against it.
 *
 * Month-to-date sums `platform_commission_cents` (the true commission, never the
 * Stripe gross) for the restaurant's orders this month, excluding refunded
 * orders — the same `refunded_at` filter the earnings and payout reports use, so
 * a reversed order stops counting against the cap just as it stops earning.
 *
 * Best-effort under concurrency: two orders placed at the same instant can each
 * read the same MTD and slip a few cents past the cap. Accepted for launch (plan
 * §1.3 / Risk R3); revisit with a per-restaurant lock only if it matters.
 */
class MonthlyCommissionCap
{
    /**
     * Commission still available under this restaurant's cap this month, floored
     * at zero. Clamp a freshly computed commission to this before charging.
     */
    public function remainingFor(Restaurant $restaurant): int
    {
        return max(0, $this->capFor($restaurant) - $this->monthToDateCents($restaurant));
    }

    /**
     * This restaurant's cap: its grandfathered override, or the platform default.
     */
    public function capFor(Restaurant $restaurant): int
    {
        return $restaurant->commission_monthly_cap_cents
            ?? (int) config('platform.commission_monthly_cap_cents');
    }

    /**
     * Commission already retained from this restaurant so far this month.
     */
    public function monthToDateCents(Restaurant $restaurant): int
    {
        return (int) Order::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('refunded_at')
            ->where('placed_at', '>=', $this->monthStart($restaurant))
            ->sum('platform_commission_cents');
    }

    /**
     * Start of the current month in the restaurant's timezone, as a UTC instant
     * to compare against the stored (UTC) `placed_at`.
     */
    private function monthStart(Restaurant $restaurant): CarbonImmutable
    {
        $timezone = $restaurant->timezone ?: config('app.timezone', 'UTC');

        return CarbonImmutable::now($timezone)->startOfMonth()->utc();
    }
}
