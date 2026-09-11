<?php

namespace App\Data;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Request;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A restaurant as a card in the app's discovery list. Part of the v1 API
 * contract: additive-only from here on.
 */
#[TypeScript]
class RestaurantSummaryData extends Data
{
    /**
     * @param  array<int, string>  $cuisineTags
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $subdomain,
        public ?string $description,
        public ?string $logoThumbUrl,
        public ?string $logoMediumUrl,
        public ?string $heroImageMediumUrl,
        public ?string $city,
        public ?string $state,
        public ?float $latitude,
        public ?float $longitude,
        /** Km from the search origin; null when the search had no location. */
        public ?float $distanceKm,
        public array $cuisineTags,
        public bool $isOpen,
        public ?string $openStatusLabel,
        public bool $deliveryEnabled,
        public string $publicUrl,
    ) {}

    public static function fromModel(Restaurant $restaurant): self
    {
        return new self(
            id: $restaurant->id,
            name: $restaurant->name,
            subdomain: $restaurant->subdomain,
            description: $restaurant->description,
            logoThumbUrl: $restaurant->logoThumbUrl(),
            logoMediumUrl: $restaurant->logoMediumUrl(),
            heroImageMediumUrl: $restaurant->heroImageMediumUrl(),
            city: $restaurant->city,
            state: $restaurant->state,
            latitude: $restaurant->latitude,
            longitude: $restaurant->longitude,
            distanceKm: isset($restaurant->distance_km) ? (float) $restaurant->distance_km : null,
            cuisineTags: $restaurant->cuisineTags(),
            isOpen: $restaurant->isOpenAt(),
            openStatusLabel: $restaurant->formatOpenStatus(),
            deliveryEnabled: (bool) $restaurant->delivery_enabled,
            publicUrl: $restaurant->publicUrl(Request::getScheme() ?: 'https'),
        );
    }
}
