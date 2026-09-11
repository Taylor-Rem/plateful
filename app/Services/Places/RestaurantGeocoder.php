<?php

namespace App\Services\Places;

use App\Models\Restaurant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Resolves a restaurant's street address to coordinates with the Google
 * Geocoding API. Its key (GOOGLE_GEOCODING_API_KEY) is a separate
 * credential from the Places key, falling back to it when unset. Coordinates feed the
 * app's near-me search; nothing else depends on them, so a failed lookup
 * simply leaves the restaurant unplaced until the next attempt.
 */
class RestaurantGeocoder
{
    public const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Geocode and store. Returns true when coordinates were written.
     */
    public function geocode(Restaurant $restaurant): bool
    {
        if (! $this->configured() || ! $restaurant->hasStreetAddress()) {
            return false;
        }

        try {
            $response = Http::timeout(8)->get(self::ENDPOINT, [
                'address' => $this->addressLine($restaurant),
                'components' => 'country:'.($restaurant->country ?: 'US'),
                'key' => $this->apiKey(),
            ]);
        } catch (ConnectionException) {
            return false;
        }

        if ($response->failed() || $response->json('status') !== 'OK') {
            return false;
        }

        $location = $response->json('results.0.geometry.location');

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return false;
        }

        $restaurant->forceFill([
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'geocoded_at' => now(),
        ])->saveQuietly();

        return true;
    }

    protected function apiKey(): string
    {
        return (string) config('services.google.geocoding_api_key', '');
    }

    protected function addressLine(Restaurant $restaurant): string
    {
        return implode(', ', array_filter([
            trim((string) $restaurant->street),
            trim((string) $restaurant->city),
            trim((string) $restaurant->state.' '.$restaurant->postal_code),
        ]));
    }
}
