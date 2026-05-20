<?php

namespace App\Services;

use App\Models\LoyaltyPoints;
use App\Models\Order;

class LoyaltyService
{
    /**
     * Award loyalty points for a completed order.
     *
     * Idempotent: if the order already has awarded_loyalty_points > 0, this is a no-op.
     * Skips guest orders (user_id null).
     */
    public function awardForOrder(Order $order): int
    {
        if ($order->user_id === null) {
            return 0;
        }

        if ((int) ($order->awarded_loyalty_points ?? 0) > 0) {
            return 0;
        }

        $rate = (int) config('platform.loyalty.points_per_dollar', 1);
        $points = (int) floor((int) $order->subtotal_cents / 100) * $rate;

        if ($points <= 0) {
            return 0;
        }

        $order->awarded_loyalty_points = $points;
        $order->save();

        $record = LoyaltyPoints::withoutTenantScope()
            ->where('user_id', $order->user_id)
            ->where('restaurant_id', $order->restaurant_id)
            ->lockForUpdate()
            ->first();

        if ($record) {
            $record->points = (int) $record->points + $points;
            $record->save();
        } else {
            LoyaltyPoints::create([
                'user_id' => $order->user_id,
                'restaurant_id' => $order->restaurant_id,
                'points' => $points,
            ]);
        }

        return $points;
    }
}
