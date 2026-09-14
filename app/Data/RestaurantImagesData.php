<?php

namespace App\Data;

use App\Models\Restaurant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The restaurant's branding image slots, returned after an upload or
 * removal so the caller sees the new URLs without the whole profile.
 */
#[TypeScript]
class RestaurantImagesData extends Data
{
    public function __construct(
        public int $restaurantId,
        public ?string $logoUrl,
        public ?string $logoMediumUrl,
        public ?string $logoThumbUrl,
        public ?string $heroImageUrl,
        public ?string $heroImageMediumUrl,
        public ?string $aboutImageUrl,
        public ?string $aboutImageMediumUrl,
    ) {}

    public static function fromModel(Restaurant $restaurant): self
    {
        return new self(
            restaurantId: $restaurant->id,
            logoUrl: $restaurant->logoUrl(),
            logoMediumUrl: $restaurant->logoMediumUrl(),
            logoThumbUrl: $restaurant->logoThumbUrl(),
            heroImageUrl: $restaurant->heroImageUrl(),
            heroImageMediumUrl: $restaurant->heroImageMediumUrl(),
            aboutImageUrl: $restaurant->aboutImageUrl(),
            aboutImageMediumUrl: $restaurant->aboutImageMediumUrl(),
        );
    }
}
