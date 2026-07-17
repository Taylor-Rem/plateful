<?php

namespace App\Services\Delivery\DoorDash;

use App\Models\Restaurant;
use App\Services\Delivery\UberDirect\UberDirectAddress;

/**
 * Formats an address into the single-line string DoorDash Drive's
 * `pickup_address` / `dropoff_address` fields expect — e.g.
 * "901 Market Street, Suite 600, San Francisco, CA 94103".
 *
 * Unlike {@see UberDirectAddress}, DoorDash
 * does NOT bind the delivery to a byte-identical replay of the quote's address
 * (the quote's `external_delivery_id` carries the pickup/dropoff forward on
 * accept), so there is no encoding-stability contract to honour here — only a
 * geocodable string. We never send coordinates; DoorDash geocodes the line.
 */
class DoorDashAddress
{
    /**
     * @param  array<string, mixed>  $snapshot  an `orders.delivery_address` snapshot
     */
    public static function fromSnapshot(array $snapshot): string
    {
        return self::compose(
            (string) ($snapshot['street'] ?? ''),
            (string) ($snapshot['street2'] ?? ''),
            (string) ($snapshot['city'] ?? ''),
            (string) ($snapshot['state'] ?? ''),
            (string) ($snapshot['postal_code'] ?? ''),
        );
    }

    /**
     * The restaurant's own address, used as the pickup end of every delivery.
     */
    public static function fromRestaurant(Restaurant $restaurant): string
    {
        return self::compose(
            (string) $restaurant->street,
            (string) $restaurant->street2,
            (string) $restaurant->city,
            (string) $restaurant->state,
            (string) $restaurant->postal_code,
        );
    }

    private static function compose(
        string $street,
        string $street2,
        string $city,
        string $state,
        string $postalCode,
    ): string {
        $parts = array_filter([
            trim($street),
            trim($street2),
            trim($city),
            trim(trim($state).' '.trim($postalCode)),
        ], static fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }
}
