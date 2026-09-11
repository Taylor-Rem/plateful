<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Notifications\OrderStatusChanged;

/**
 * Turns order and courier milestones into pushes. Milestones only — the
 * customer hears "confirmed", "ready", "on its way", "delivered", "complete"
 * and "cancelled", never the kitchen's internal steps. Guests and customers
 * who switched push off hear nothing.
 */
class OrderNotifier
{
    public function orderTransitioned(Order $order, OrderStatus $to): void
    {
        $order->loadMissing('restaurant');
        $restaurant = (string) $order->restaurant?->name;
        $number = $order->number;

        [$title, $body] = match (true) {
            $to === OrderStatus::Confirmed => ["Order {$number} confirmed", "{$restaurant} is on it."],
            $to === OrderStatus::Ready && $order->type === OrderType::Pickup => ["Order {$number} is ready", "Pick it up whenever you're ready at {$restaurant}."],
            $to === OrderStatus::Completed => ["Order {$number} complete", "Thanks for ordering from {$restaurant}!"],
            $to === OrderStatus::Cancelled => ["Order {$number} cancelled", "{$restaurant} cancelled your order. Any payment is being refunded."],
            default => [null, null],
        };

        if ($title === null) {
            return;
        }

        $this->push($order, $title, $body, $to->value);
    }

    public function deliveryChanged(DeliveryAssignment $assignment, DeliveryStatus $to): void
    {
        $order = $assignment->order;

        if ($order === null) {
            return;
        }

        $order->loadMissing('restaurant');
        $restaurant = (string) $order->restaurant?->name;
        $number = $order->number;
        $driver = trim((string) $assignment->driver_name);

        [$title, $body] = match ($to) {
            DeliveryStatus::PickedUp => [
                'Your order is on its way',
                ($driver !== '' ? "{$driver} picked up" : 'Your courier picked up')." order {$number} from {$restaurant}.",
            ],
            DeliveryStatus::Delivered => ["Order {$number} delivered", "Enjoy your meal from {$restaurant}."],
            default => [null, null],
        };

        if ($title === null) {
            return;
        }

        $this->push($order, $title, $body, 'delivery_'.$to->value);
    }

    protected function push(Order $order, string $title, string $body, string $status): void
    {
        $user = $order->user;

        if ($user === null || ! $user->push_order_updates) {
            return;
        }

        $user->notify(new OrderStatusChanged($title, $body, [
            'type' => 'order',
            'orderNumber' => $order->number,
            'restaurant' => (string) $order->restaurant?->subdomain,
            'status' => $status,
        ]));
    }
}
