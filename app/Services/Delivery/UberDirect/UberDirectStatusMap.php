<?php

namespace App\Services\Delivery\UberDirect;

use App\Enums\DeliveryStatus;

/**
 * Uber's delivery lifecycle mapped onto Plateful's smaller vocabulary. Shared
 * by the provider (polling `status()`) and the status webhook so the two can
 * never disagree about what a given Uber status means.
 *
 * These are the *Direct* (customer-scoped) status values, which are lowercase.
 * The store-scoped Eats API uses a different, uppercase set (SCHEDULED,
 * EN_ROUTE_TO_PICKUP, …) — if you see those, you're reading the wrong docs.
 */
class UberDirectStatusMap
{
    public static function toDeliveryStatus(?string $uberStatus): DeliveryStatus
    {
        return match ($uberStatus) {
            'pending' => DeliveryStatus::Pending,
            // A courier now exists and is heading to the kitchen. This is the
            // signal auth/capture (§8) waits on before charging anyone.
            'pickup', 'pickup_imminent', 'pickup_complete', 'shopping_completed' => DeliveryStatus::DriverAssigned,
            'dropoff', 'dropoff_imminent' => DeliveryStatus::PickedUp,
            'delivered' => DeliveryStatus::Delivered,
            'canceled', 'cancelled' => DeliveryStatus::Cancelled,
            'returned', 'failed' => DeliveryStatus::Failed,
            default => DeliveryStatus::Pending,
        };
    }

    /**
     * Whether a courier has been assigned — i.e. the delivery is real and
     * someone is actually coming for it.
     */
    public static function hasCourier(DeliveryStatus $status): bool
    {
        return in_array($status, [
            DeliveryStatus::DriverAssigned,
            DeliveryStatus::PickedUp,
            DeliveryStatus::Delivered,
        ], strict: true);
    }
}
