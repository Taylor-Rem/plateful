<?php

namespace App\Services\Refunds;

use App\Enums\OrderType;
use App\Models\Order;
use App\Services\Delivery\DeliveryCancellation;

/**
 * Works out exactly what a cancelled order may refund without Plateful ever
 * being out of pocket (DoorDash plan Session 5 / §1).
 *
 * The guiding rule: refund the customer only money that is actually recoverable
 * — the FOOD portion when the restaurant's policy allows it, and the DELIVERY
 * portion only when the courier network gives its fee back. Everything Plateful
 * fronted (the courier fee under central billing) is refunded only against money
 * it gets back, so a refund never comes out of Plateful's pocket.
 */
class RefundCalculator
{
    /**
     * @param  DeliveryCancellation|null  $cancellation  what the courier provider
     *                                                   did with its fee (null when the order had no dispatched courier)
     */
    public function for(Order $order, ?DeliveryCancellation $cancellation): RefundPlan
    {
        $restaurant = $order->relationLoaded('restaurant')
            ? $order->getRelation('restaurant')
            : $order->restaurant()->first();

        if ($restaurant === null) {
            return RefundPlan::none();
        }

        $isDelivery = $order->type === OrderType::Delivery;

        // The restaurant's food-refund policy for this order's channel.
        $refundFood = $isDelivery
            ? (bool) $restaurant->delivery_refunds_enabled
            : (bool) $restaurant->pickup_refunds_enabled;

        $commission = (int) $order->platform_commission_cents;
        $margin = (int) $order->delivery_margin_cents;
        $courier = (int) $order->courier_fee_cents;
        $tip = (int) $order->tip_cents;
        $total = (int) $order->total_cents;

        // A centrally-billed delivery is the only case where Plateful fronted a
        // courier fee. courier_fee_cents is populated for exactly those orders
        // (OrderPlacement sets it only when the provider isCentrallyBilled).
        $isCentral = $courier > 0;

        if (! $isCentral) {
            // Pickup, self-delivery, or a pass-through provider: the Stripe
            // application fee is just the commission, and every dollar the
            // customer paid is restaurant/staff money. Refund is all-or-nothing
            // on the food policy — the historical full-refund behaviour.
            if (! $refundFood) {
                return RefundPlan::none();
            }

            return new RefundPlan(
                customerRefundCents: $total,
                applicationFeeReversalCents: $commission,
                reverseCommission: $commission > 0,
                reverseMargin: false,
                isFullRefund: true,
            );
        }

        // Central billing: food and delivery are refunded independently.
        // The delivery line (customer's grossed-up fee + tip) is recoverable
        // only when DoorDash gave the courier fee back (pre-pickup).
        $refundDelivery = $cancellation !== null && $cancellation->courierFeeRecoverable();

        $food = (int) $order->subtotal_cents + (int) $order->tax_cents;
        $deliveryFee = (int) $order->delivery_fee_cents;

        $customerRefund = ($refundFood ? $food : 0)
            + ($refundDelivery ? $deliveryFee + $tip : 0);

        // Reverse the matching slices of the Stripe gross: the commission for
        // the food, and the courier + margin + tip passthrough for the delivery.
        $appFeeReversal = ($refundFood ? $commission : 0)
            + ($refundDelivery ? $courier + $margin + $tip : 0);

        if ($customerRefund <= 0) {
            return RefundPlan::none();
        }

        return new RefundPlan(
            customerRefundCents: $customerRefund,
            applicationFeeReversalCents: $appFeeReversal,
            reverseCommission: $refundFood && $commission > 0,
            reverseMargin: $refundDelivery && $margin > 0,
            isFullRefund: $customerRefund >= $total,
        );
    }
}
