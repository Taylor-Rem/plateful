<?php

use App\Enums\DeliveryProviderName;
use App\Models\DeliveryQuote;
use App\Models\Restaurant;
use App\Services\Delivery\UberDirect\UberDirectAddress;
use Illuminate\Support\Str;

if (! function_exists('quoteAddress')) {
    /**
     * The canonical delivery address used across quote tests.
     *
     * @return array<string, string|null>
     */
    function quoteAddress(array $overrides = []): array
    {
        return array_merge([
            'street' => '285 Fulton St',
            'street2' => null,
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10006',
            'country' => 'US',
            'instructions' => null,
        ], $overrides);
    }
}

if (! function_exists('makeDeliveryQuote')) {
    /**
     * A stored quote as the checkout endpoint would have written it. Third-party
     * delivery orders require one — that is the point of the checkout rework.
     *
     * @param  array<string, mixed>|null  $address
     */
    function makeDeliveryQuote(
        Restaurant $restaurant,
        ?array $address = null,
        int $feeCents = 799,
        ?string $expiresAt = null,
    ): DeliveryQuote {
        $address ??= quoteAddress();

        return DeliveryQuote::withoutTenantScope()->create([
            'token' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'provider' => DeliveryProviderName::Uber,
            'external_quote_id' => 'dqt_'.Str::random(12),
            'dropoff_address' => $address,
            'dropoff_address_payload' => UberDirectAddress::fromSnapshot($address),
            'pickup_address_payload' => UberDirectAddress::fromRestaurant($restaurant),
            'fee_cents' => $feeCents,
            'eta_minutes' => 44,
            'pickup_duration_minutes' => 18,
            'expires_at' => $expiresAt ?? now()->addMinutes(15),
        ]);
    }
}
