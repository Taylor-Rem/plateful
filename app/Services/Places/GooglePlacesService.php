<?php

namespace App\Services\Places;

use Illuminate\Support\Facades\Http;

/**
 * Address autocomplete via the Google Places API (New), proxied server-side.
 *
 * The key never reaches the browser. That is deliberate (see §0 of the Uber
 * Direct plan): a browser key is protected only by an HTTP-referrer allowlist,
 * and this app is multi-tenant with custom domains, so every restaurant
 * onboarded would be another referrer to maintain. An IP-restricted server key
 * has no such problem — and it lets the suggestion dropdown be ours, styled in
 * the restaurant's palette, instead of Google's web component.
 *
 * Endpoints are the NEW `places.googleapis.com` ones, not the legacy
 * `maps.googleapis.com/maps/api/place/*`. The legacy widget was deprecated for
 * new customers in March 2025.
 */
class GooglePlacesService
{
    public const HOST = 'https://places.googleapis.com';

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Address suggestions for a partial input.
     *
     * `sessionToken` matters for money, not correctness: Google bills an
     * autocomplete session as one unit only when the same token is passed to
     * every keystroke AND to the details lookup that resolves the choice.
     * Without it each request bills separately.
     *
     * @return array<int, array{placeId: string, description: string, mainText: string, secondaryText: string}>
     */
    public function autocomplete(string $input, string $sessionToken): array
    {
        if (trim($input) === '' || ! $this->configured()) {
            return [];
        }

        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey(),
        ])->timeout(8)->post(self::HOST.'/v1/places:autocomplete', [
            'input' => $input,
            'sessionToken' => $sessionToken,
            'includedRegionCodes' => ['us'],
            // Street addresses only — a customer cannot have dinner delivered to
            // a city or a national park.
            'includedPrimaryTypes' => ['street_address', 'premise', 'subpremise'],
        ]);

        if ($response->failed()) {
            return [];
        }

        $suggestions = [];

        foreach ((array) $response->json('suggestions', []) as $suggestion) {
            $prediction = $suggestion['placePrediction'] ?? null;

            if (! is_array($prediction) || ! isset($prediction['placeId'])) {
                continue;
            }

            $suggestions[] = [
                'placeId' => (string) $prediction['placeId'],
                'description' => (string) ($prediction['text']['text'] ?? ''),
                'mainText' => (string) ($prediction['structuredFormat']['mainText']['text'] ?? ''),
                'secondaryText' => (string) ($prediction['structuredFormat']['secondaryText']['text'] ?? ''),
            ];
        }

        return $suggestions;
    }

    /**
     * Resolve a chosen suggestion into the structured snapshot the rest of the
     * system speaks — the same shape as `orders.delivery_address`.
     *
     * Returns null when the place cannot be resolved into a street address we
     * could actually deliver to.
     *
     * @return array<string, string>|null
     */
    public function addressSnapshot(string $placeId, string $sessionToken): ?array
    {
        if ($placeId === '' || ! $this->configured()) {
            return null;
        }

        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey(),
            // Places (New) bills by the fields you ask for, and rejects the
            // request outright without a mask. Ask for nothing we don't use.
            'X-Goog-FieldMask' => 'id,formattedAddress,addressComponents',
        ])->timeout(8)->get(self::HOST.'/v1/places/'.urlencode($placeId), [
            'sessionToken' => $sessionToken,
        ]);

        if ($response->failed()) {
            return null;
        }

        return $this->snapshotFromComponents((array) $response->json('addressComponents', []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, string>|null
     */
    private function snapshotFromComponents(array $components): ?array
    {
        $byType = [];

        foreach ($components as $component) {
            foreach ((array) ($component['types'] ?? []) as $type) {
                $byType[$type] = [
                    'long' => (string) ($component['longText'] ?? ''),
                    'short' => (string) ($component['shortText'] ?? ''),
                ];
            }
        }

        $streetNumber = $byType['street_number']['long'] ?? '';
        $route = $byType['route']['long'] ?? '';
        $street = trim($streetNumber.' '.$route);

        // `postal_town` is the UK/IE stand-in for `locality`. Cheap to accept
        // now; it costs one `??` and saves a confusing bug if we ever expand.
        $city = $byType['locality']['long'] ?? $byType['postal_town']['long'] ?? '';

        // Short form: Uber's structured address wants "UT", not "Utah".
        $state = $byType['administrative_area_level_1']['short'] ?? '';
        $postalCode = $byType['postal_code']['long'] ?? '';
        $country = $byType['country']['short'] ?? 'US';

        // Without a street a delivery cannot happen, and the other fields are
        // what Uber's structured address requires. A partial snapshot would
        // only fail later, further from the customer who could fix it.
        if ($street === '' || $city === '' || $state === '' || $postalCode === '') {
            return null;
        }

        return [
            'street' => $street,
            // Places does not reliably return a unit, which is why the checkout
            // asks for it in its own field. Never guess it from `subpremise`.
            'street2' => '',
            'city' => $city,
            'state' => $state,
            'postal_code' => $postalCode,
            'country' => $country ?: 'US',
        ];
    }

    private function apiKey(): string
    {
        return (string) config('services.google.maps_api_key', '');
    }
}
