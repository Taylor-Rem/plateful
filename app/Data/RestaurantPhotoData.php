<?php

namespace App\Data;

use App\Models\RestaurantPhoto;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RestaurantPhotoData extends Data
{
    public function __construct(
        public int $id,
        public ?string $caption,
        public int $position,
        public ?string $imageUrl,
        public ?string $imageMediumUrl,
        public ?string $imageThumbUrl,
    ) {}

    public static function fromModel(RestaurantPhoto $photo): self
    {
        return new self(
            id: $photo->id,
            caption: $photo->caption,
            position: (int) $photo->position,
            imageUrl: $photo->imageUrl(),
            imageMediumUrl: $photo->imageMediumUrl(),
            imageThumbUrl: $photo->imageThumbUrl(),
        );
    }
}
