<?php

namespace App\Services\Delivery\UberDirect;

use App\Models\Restaurant;

/**
 * Encodes an address into the shape Uber Direct's `pickup_address` /
 * `dropoff_address` fields expect: a JSON *string*, not a JSON object.
 *
 *   "{\"street_address\":[\"20 W 34th St\",\"Floor 2\"],\"city\":\"New York\",…}"
 *
 * This class exists to make one rule enforceable: Uber compares the address on
 * create against the address the quote was issued for, and rejects the delivery
 * with `delivery location changed` if they differ. Encoding must therefore be
 * DETERMINISTIC — same input, byte-identical output — so quote and create agree.
 * Both paths encode from the same `orders.delivery_address` snapshot through
 * this one function, and key order is fixed by construction rather than by
 * whatever order an array happened to be built in.
 *
 * Note we never send lat/lng. Uber silently overrides coordinates that sit more
 * than 1km from the stated address with its own geocoding, so an address-only
 * payload is both simpler and closer to what Uber will actually use.
 */
class UberDirectAddress
{
    /**
     * @param  array<string, mixed>  $snapshot  an `orders.delivery_address` snapshot
     */
    public static function fromSnapshot(array $snapshot): string
    {
        return self::encode(
            street: (string) ($snapshot['street'] ?? ''),
            street2: (string) ($snapshot['street2'] ?? ''),
            city: (string) ($snapshot['city'] ?? ''),
            state: (string) ($snapshot['state'] ?? ''),
            postalCode: (string) ($snapshot['postal_code'] ?? ''),
            country: (string) ($snapshot['country'] ?? 'US') ?: 'US',
        );
    }

    /**
     * The restaurant's own address, used as the pickup end of every delivery.
     */
    public static function fromRestaurant(Restaurant $restaurant): string
    {
        return self::encode(
            street: (string) $restaurant->street,
            street2: (string) $restaurant->street2,
            city: (string) $restaurant->city,
            state: (string) $restaurant->state,
            postalCode: (string) $restaurant->postal_code,
            country: 'US',
        );
    }

    /**
     * Fixed key order, fixed shape. `street_address` is a two-element array —
     * Uber's own examples pass an empty string for a missing unit rather than
     * omitting the slot, and we match that so the encoding stays stable whether
     * or not the customer entered an apartment.
     */
    private static function encode(
        string $street,
        string $street2,
        string $city,
        string $state,
        string $postalCode,
        string $country,
    ): string {
        return json_encode([
            'street_address' => [$street, $street2],
            'city' => $city,
            'state' => $state,
            'zip_code' => $postalCode,
            'country' => $country,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
