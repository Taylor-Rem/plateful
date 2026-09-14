<?php

namespace App\Data;

use App\Enums\ApiKeyScope;
use App\Models\Restaurant;
use App\Support\Api\ApiActor;
use Illuminate\Support\Facades\Request;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A restaurant as the operator API lists it: lifecycle state included, since
 * an operator works a restaurant before it is live. Part of the v1
 * contract: additive-only from here on.
 */
#[TypeScript]
class OperatorRestaurantData extends Data
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $subdomain,
        public string $status,
        public bool $isActive,
        public bool $isLive,
        public bool $isStripeReady,
        public bool $deliveryEnabled,
        public ?string $city,
        public ?string $state,
        public string $timezone,
        public string $publicUrl,
        /** The actor's pivot role here; null for a restaurant key. */
        public ?string $role,
        /** What the actor may do here. */
        public array $scopes,
    ) {}

    public static function fromModel(Restaurant $restaurant, ApiActor $actor): self
    {
        return new self(
            id: $restaurant->id,
            name: $restaurant->name,
            subdomain: $restaurant->subdomain,
            status: $restaurant->status->value,
            isActive: (bool) $restaurant->is_active,
            isLive: $restaurant->isLive(),
            isStripeReady: $restaurant->isStripeReady(),
            deliveryEnabled: (bool) $restaurant->delivery_enabled,
            city: $restaurant->city,
            state: $restaurant->state,
            timezone: (string) $restaurant->timezone,
            publicUrl: $restaurant->publicUrl(Request::getScheme() ?: 'https'),
            role: $actor->roleAt($restaurant)?->value,
            scopes: array_map(fn (ApiKeyScope $s) => $s->value, $actor->scopesAt($restaurant)),
        );
    }
}
