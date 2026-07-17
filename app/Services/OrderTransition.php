<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentState;
use App\Exceptions\InvalidOrderTransitionException;
use App\Mail\OrderCancelledToCustomer;
use App\Mail\OrderReadyForPickupToCustomer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Delivery\DeliveryCancellation;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Refunds\RefundCalculator;
use App\Services\Refunds\RefundPlan;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderTransition
{
    public function __construct(
        protected LoyaltyService $loyalty,
        protected StripeConnectService $connect,
        protected DeliveryDispatcher $dispatcher,
        protected RefundCalculator $refunds,
        protected RevenueSplitResolver $revenueSplits,
    ) {}

    public function apply(
        Order $order,
        OrderStatus $toStatus,
        ?User $byUser,
        ?string $note = null,
    ): Order {
        $fromStatus = $order->status;

        if (! $fromStatus->canTransitionTo($toStatus)) {
            throw new InvalidOrderTransitionException($fromStatus, $toStatus);
        }

        DB::transaction(function () use ($order, $fromStatus, $toStatus, $byUser, $note): void {
            OrderEvent::create([
                'order_id' => $order->id,
                'from_status' => $fromStatus->value,
                'to_status' => $toStatus->value,
                'occurred_at' => now(),
                'user_id' => $byUser?->id,
                'note' => $note,
            ]);

            $order->status = $toStatus;
            $order->save();

            if ($toStatus === OrderStatus::Completed) {
                $this->loyalty->awardForOrder($order);
            }
        });

        $order->loadMissing(['items', 'restaurant']);

        if ($toStatus === OrderStatus::Cancelled) {
            $this->refundOnCancel($order);

            Mail::to($order->customer_email)
                ->queue(new OrderCancelledToCustomer($order, $note));
        } elseif ($toStatus === OrderStatus::Ready && $order->type === OrderType::Pickup) {
            Mail::to($order->customer_email)
                ->queue(new OrderReadyForPickupToCustomer($order));
        }

        return $order;
    }

    /**
     * Give the customer their money back when a paid order is cancelled — by
     * whichever mechanism actually applies. Best-effort: a Stripe failure must
     * not block the cancel.
     *
     * Order of operations matters: the courier network is told to stop BEFORE
     * any money moves, so a Dasher isn't still driving to a kitchen for an order
     * that no longer exists — and its cancel response tells us whether the
     * courier fee is recoverable.
     *
     * An order awaiting a courier holds an AUTHORIZATION, not a charge, and
     * Stripe rejects a refund against an uncaptured intent. So the hold is
     * released instead. A captured order is refunded only what is recoverable
     * (DoorDash plan Session 5): the food per the restaurant's policy, and the
     * delivery line only when the courier fee came back — Plateful is never out
     * of pocket.
     */
    protected function refundOnCancel(Order $order): void
    {
        // Stop the courier first, whatever happens to the money next.
        $cancellation = $this->cancelCourier($order);

        if (! $order->stripe_payment_intent_id) {
            return;
        }

        if ($order->payment_state === PaymentState::Authorized) {
            $this->voidUncapturedHold($order);

            return;
        }

        // Only captured money can be refunded. A hold that was already released
        // has nothing to give back, and asking Stripe to refund it would fail —
        // matching on "not Authorized" instead of "is Captured" would send
        // every voided order down this path.
        if ($order->payment_state !== PaymentState::Captured || $order->refunded_at) {
            return;
        }

        $plan = $this->refunds->for($order, $cancellation);

        if (! $plan->refundsAnything()) {
            OrderEvent::note($order, 'Order cancelled — no refund issued under the restaurant’s refund policy.');

            return;
        }

        try {
            if ($plan->isFullRefund) {
                // A full refund can reverse the whole application fee in one
                // call — cheaper and matches the pre-Session-5 behaviour exactly.
                $this->connect->refundOrder($order);
            } else {
                $this->connect->refundOrderPartial(
                    $order,
                    $plan->customerRefundCents,
                    $plan->applicationFeeReversalCents,
                );
            }
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $this->applyRefund($order, $plan);
    }

    /**
     * Persist the effect of a refund: what the customer got back, and which
     * slices of Plateful's revenue were reversed. Zeroing the columns and
     * deleting the matching ledger rows keeps the monthly cap and the earnings
     * split honest — `refunded_at` is set only on a complete refund, so a
     * partial (delivery-only or food-only) refund still counts its retained
     * revenue toward month-to-date.
     */
    protected function applyRefund(Order $order, RefundPlan $plan): void
    {
        $order->refunded_cents = (int) $order->refunded_cents + $plan->customerRefundCents;

        if ($plan->reverseCommission) {
            $order->platform_commission_cents = 0;
        }

        if ($plan->reverseMargin) {
            $order->delivery_margin_cents = 0;
        }

        if ($plan->isFullRefund) {
            $order->refunded_at = now();
        }

        $order->save();

        $this->revenueSplits->reverse($order, $plan->reverseCommission, $plan->reverseMargin);

        OrderEvent::note($order, sprintf(
            'Refunded $%s to the customer on cancellation.',
            number_format($plan->customerRefundCents / 100, 2),
        ));
    }

    /**
     * Call off a dispatched courier before releasing the money, and report back
     * what the provider did with its fee (null when there was no live courier).
     *
     * Best-effort: a failed cancel must not block the order cancel. When we
     * can't confirm the outcome we assume the courier fee was kept, so the
     * delivery line is never refunded on a guess — Plateful stays whole.
     */
    protected function cancelCourier(Order $order): ?DeliveryCancellation
    {
        $assignment = $order->deliveryAssignment;

        if ($assignment === null || $assignment->status === DeliveryStatus::Cancelled) {
            return null;
        }

        try {
            return $this->dispatcher->cancel($assignment);
        } catch (\Throwable $e) {
            report($e);

            return DeliveryCancellation::courierFeeRetained((int) $assignment->actual_fee_cents);
        }
    }

    /**
     * Release a hold on an order cancelled before its courier was confirmed.
     * Nothing is "refunded" because nothing was ever charged — so
     * `refunded_at`/`refunded_cents` stay null, and the money never moved.
     */
    protected function voidUncapturedHold(Order $order): void
    {
        try {
            $this->connect->voidPayment($order);
            $order->forceFill([
                'payment_state' => PaymentState::Voided,
                'voided_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
