<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
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
     * Full refund (reversing Plateful's application fee) when a paid order is
     * cancelled. Best-effort — a Stripe failure must not block the cancel.
     */
    protected function refundOnCancel(Order $order): void
    {
        if (! $order->stripe_payment_intent_id || $order->refunded_at) {
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
}
