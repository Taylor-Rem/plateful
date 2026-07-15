<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Mail\OrderCancelledToCustomer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\OrderPlacement;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Resolves the money on an authorized delivery order, one way or the other.
 *
 * Every courier-network delivery leaves checkout as a HOLD, because Uber only
 * looks for a driver *after* the delivery is created — so checkout is too early
 * for anyone to honestly say the delivery will happen. Exactly one of these two
 * outcomes must eventually run, or the customer's funds sit held for days:
 *
 *   onCourierConfirmed() — a driver exists → take the money, print the ticket
 *   onCourierUnavailable() — no driver → release the hold, cancel, apologise
 *
 * Both are idempotent on `payment_state`, because both can be reached more than
 * once: Uber retries webhooks, and the deadline job races the webhook by design.
 */
class DeliverySettlement
{
    public function __construct(
        private StripeConnectService $connect,
        private OrderPlacement $placement,
    ) {}

    /**
     * A courier is assigned. This is the first moment the delivery is real, so
     * it is the moment the money becomes real and the kitchen starts cooking.
     */
    public function onCourierConfirmed(Order $order): void
    {
        if ($order->payment_state !== PaymentState::Authorized) {
            return;
        }

        if ($order->stripe_payment_intent_id === null) {
            return;
        }

        try {
            $this->connect->capturePayment($order);
        } catch (Throwable $e) {
            // Leave it Authorized so the deadline job or a later webhook can
            // try again. Silently flipping to Captured would be worse: we would
            // believe we had money we never took.
            Log::error('Failed to capture authorized delivery order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return;
        }

        $order->forceFill([
            'payment_state' => PaymentState::Captured,
            'captured_at' => now(),
        ])->save();

        OrderEvent::note($order, 'Courier confirmed — payment captured.');

        // The push OrderPlacement held back. Now that a courier is coming, the
        // ticket is safe to print.
        $this->placement->queuePosPush($order);
    }

    /**
     * No courier is coming. Release the hold and tell the customer.
     *
     * The order is cancelled directly rather than through OrderTransition,
     * because that path would try to REFUND — and Stripe rejects a refund on an
     * uncaptured intent. There is nothing to refund here; there was never a
     * charge, which is the whole point.
     */
    public function onCourierUnavailable(Order $order, string $reason): void
    {
        if ($order->payment_state !== PaymentState::Authorized) {
            return;
        }

        $voided = false;

        if ($order->stripe_payment_intent_id !== null) {
            try {
                $this->connect->voidPayment($order);
                $voided = true;
            } catch (Throwable $e) {
                // Cancel the order anyway: an uncancelled order with no courier
                // is worse than a hold we failed to release, and the hold
                // expires on its own. But say so loudly.
                Log::error('Failed to void authorized delivery order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        DB::transaction(function () use ($order, $reason, $voided): void {
            $from = $order->status;

            $order->forceFill([
                'payment_state' => $voided ? PaymentState::Voided : $order->payment_state,
                'voided_at' => $voided ? now() : null,
                'status' => OrderStatus::Cancelled,
            ])->save();

            OrderEvent::create([
                'order_id' => $order->id,
                'from_status' => $from->value,
                'to_status' => OrderStatus::Cancelled->value,
                'occurred_at' => now(),
                'user_id' => null,
                'note' => $voided
                    ? "Delivery cancelled: {$reason}. Payment hold released — the customer was never charged."
                    : "Delivery cancelled: {$reason}. RELEASING THE PAYMENT HOLD FAILED — check Stripe.",
            ]);
        });

        Mail::to($order->customer_email)->queue(new OrderCancelledToCustomer(
            $order,
            'We couldn’t find a driver for your delivery, so your order has been cancelled. '
            .'You have not been charged — any pending hold will drop off shortly.',
        ));
    }
}
