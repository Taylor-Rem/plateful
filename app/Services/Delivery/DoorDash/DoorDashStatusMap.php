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
    /**
     * Webhooks do not carry `delivery_status`. They carry `event_name` (the
     * trigger — `DASHER_CONFIRMED`, `DASHER_PICKED_UP`, …) plus the delivery
     * object minus its status. This translates an event into the same
     * `delivery_status` vocabulary the polling path reads, so both paths feed
     * {@see toDeliveryStatus()} and the stored `provider_status` stays one
     * vocabulary. Returns null for events that carry no lifecycle change
     * (`DELIVERY_BATCHED`) or that we do not recognise — callers keep the
     * status they already have rather than regressing to Pending.
     *
     * Event names per DoorDash's webhook reference (Drive, not classic).
     */
    public static function deliveryStatusForEvent(?string $eventName): ?string
    {
        if ($eventName === null) {
            return null;
        }

        return match (strtoupper($eventName)) {
            'DASHER_CONFIRMED' => 'confirmed',
            'DASHER_ENROUTE_TO_PICKUP' => 'enroute_to_pickup',
            'DASHER_CONFIRMED_PICKUP_ARRIVAL' => 'arrived_at_store',
            'DASHER_PICKED_UP' => 'picked_up',
            'DASHER_ENROUTE_TO_DROPOFF' => 'enroute_to_dropoff',
            'DASHER_CONFIRMED_DROPOFF_ARRIVAL' => 'arrived_at_consumer',
            'DASHER_DROPPED_OFF' => 'delivered',
            'DELIVERY_CANCELLED' => 'cancelled',
            // A failed dropoff coming back to the kitchen. The money was
            // captured at confirmation, so this is informational to settlement.
            'DELIVERY_RETURN_INITIALIZED',
            'DASHER_ENROUTE_TO_RETURN',
            'DASHER_CONFIRMED_RETURN_ARRIVAL' => 'delivery_attempt_failed',
            'DELIVERY_RETURNED' => 'returned',
            default => null,
        };
    }

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
