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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderTransition
{
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
        });

        $order->loadMissing(['items', 'restaurant']);

        if ($toStatus === OrderStatus::Cancelled) {
            Mail::to($order->customer_email)
                ->queue(new OrderCancelledToCustomer($order, $note));
        } elseif ($toStatus === OrderStatus::Ready && $order->type === OrderType::Pickup) {
            Mail::to($order->customer_email)
                ->queue(new OrderReadyForPickupToCustomer($order));
        }

        return $order;
    }
}
