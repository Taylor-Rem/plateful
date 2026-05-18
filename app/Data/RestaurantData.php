<?php

namespace App\Data;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RestaurantData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $subdomain,
        public ?string $description,
        public ?string $logoUrl,
        public ?string $primaryColor,
        public ?string $secondaryColor,
    ) {}

    public static function fromModel(Restaurant $restaurant): self
    {
        return new self(
            id: $restaurant->id,
            name: $restaurant->name,
            subdomain: $restaurant->subdomain,
            description: $restaurant->description,
            logoUrl: $restaurant->logo_path ? Storage::url($restaurant->logo_path) : null,
            primaryColor: $restaurant->primary_color,
            secondaryColor: $restaurant->secondary_color,
        );
    }
}
