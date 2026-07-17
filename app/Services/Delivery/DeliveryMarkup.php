<?php

namespace App\Services\Delivery;

/**
 * The central-billing delivery-fee math (DoorDash plan §1), in one place so the
 * price quoted to the customer and the price they are charged can never drift.
 *
 * Only applies to a centrally-billed provider (DoorDash), where Plateful pays
 * the courier and recovers the cost through the Stripe application fee. Given the
 * courier quote `D` (cents), the restaurant's Plateful rate `pct` (percent, the
 * same rate charged on food), and Stripe's variable rate `rate` (config):
 *
 *   marginCents(D)      = round(D × pct/100)                 // Plateful's cut of the delivery
 *   customerFeeCents(D) = round(D × (1 + pct/100) / (1−rate))
 *
 * The customer fee grosses up the courier cost by the margin AND by Stripe's
 * variable fee, so after Stripe takes its cut of the delivery line the
 * restaurant is left exactly whole on it — it bears no Stripe fee on delivery.
 * Stripe's fixed 30¢ is deliberately excluded: it is the restaurant's normal
 * per-charge card cost, which it already bears on the food.
 *
 * The plan writes these with a literal 4% (1.04 / 0.04); this generalizes the
 * 4% to the restaurant's own `application_fee_percent`, so a restaurant on a
 * negotiated rate gets a consistent margin on food and delivery alike. At the
 * 4% default the numbers match the plan's worked example to the cent.
 */
class DeliveryMarkup
{
    /**
     * What the customer pays for delivery: the courier quote grossed up by the
     * Plateful margin and Stripe's variable-fee recovery.
     */
    public static function customerFeeCents(int $courierFeeCents, float $marginPercent): int
    {
        $rate = self::stripeVariableRate();
        $markup = 1 + max(0.0, $marginPercent) / 100;

        return (int) round($courierFeeCents * $markup / (1 - $rate));
    }

    /**
     * Plateful's margin on the delivery — its true revenue from the courier
     * line, split to the founder by RevenueSplitResolver.
     */
    public static function marginCents(int $courierFeeCents, float $marginPercent): int
    {
        return (int) round($courierFeeCents * max(0.0, $marginPercent) / 100);
    }

    private static function stripeVariableRate(): float
    {
        $rate = (float) config('platform.stripe_variable_rate');

        // Guard against a misconfigured rate driving the denominator to zero or
        // negative; fall back to no Stripe recovery rather than divide by ~0.
        return $rate > 0 && $rate < 1 ? $rate : 0.0;
    }
}
