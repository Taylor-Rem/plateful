<?php

namespace App\Services\Delivery\DoorDash;

use App\Enums\DeliveryStatus;

/**
 * DoorDash Drive's delivery lifecycle mapped onto Plateful's smaller vocabulary.
 * Shared by the provider (polling `status()`) and — from Session 3 — the status
 * webhook, so the two can never disagree about what a given DoorDash status
 * means.
 *
 * DoorDash reports progress in the `delivery_status` field. `created` means the
 * delivery exists but no Dasher is committed yet; `confirmed` is the first
 * status at which a Dasher has been assigned — that is the signal auth/capture
 * (§8) waits on, the DoorDash equivalent of Uber's `pickup`.
 */
class DoorDashStatusMap
{
    public static function toDeliveryStatus(?string $doordashStatus): DeliveryStatus
    {
        return match ($doordashStatus) {
            'created', 'quote', 'scheduled' => DeliveryStatus::Pending,
            // A Dasher is now committed and heading to the kitchen.
            'confirmed', 'enroute_to_pickup', 'arrived_at_store', 'arrived_at_pickup' => DeliveryStatus::DriverAssigned,
            'picked_up', 'enroute_to_dropoff', 'arrived_at_dropoff', 'arrived_at_consumer' => DeliveryStatus::PickedUp,
            'delivered' => DeliveryStatus::Delivered,
            'cancelled', 'canceled' => DeliveryStatus::Cancelled,
            'delivery_attempt_failed', 'returned', 'failed' => DeliveryStatus::Failed,
            default => DeliveryStatus::Pending,
        };
    }
}
