<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentState;
use App\Exceptions\InvalidOrderTransitionException;
use App\Mail\OrderCancelledToCustomer;
use App\Mail\OrderReadyForPickupToCustomer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderTransition
{
    public function __construct(
        protected LoyaltyService $loyalty,
        protected StripeConnectService $connect,
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
     * An order awaiting a courier holds an AUTHORIZATION, not a charge, and
     * Stripe rejects a refund against an uncaptured intent. So the hold is
     * released instead. Without this split, an owner cancelling a delivery
     * order before its courier was found would hit a Stripe error, the refund
     * would silently fail, and the hold would sit on the card until the bank
     * dropped it.
     */
    protected function refundOnCancel(Order $order): void
    {
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

        try {
            $this->connect->refundOrder($order);
            $order->forceFill([
                'refunded_at' => now(),
                'refunded_cents' => (int) $order->total_cents,
            ])->save();
        } catch (\Throwable $e) {
            report($e);
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
