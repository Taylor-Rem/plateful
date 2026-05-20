<?php

namespace App\Data;

use App\Models\Restaurant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RestaurantData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $subdomain,
        public ?string $customDomain,
        public ?string $description,
        public ?string $logoUrl,
        public ?string $logoMediumUrl,
        public ?string $logoThumbUrl,
        public ?string $primaryColor,
        public ?string $secondaryColor,
        public ?string $email,
        public ?string $phone,
        public float $taxRatePercent,
        public int $deliveryFeeCents,
        public bool $isActive,
        public ?string $createdAt,
    ) {}

    public static function fromModel(Restaurant $restaurant): self
    {
        return new self(
            id: $restaurant->id,
            name: $restaurant->name,
            subdomain: $restaurant->subdomain,
            customDomain: $restaurant->custom_domain,
            description: $restaurant->description,
            logoUrl: $restaurant->logoUrl(),
            logoMediumUrl: $restaurant->logoMediumUrl(),
            logoThumbUrl: $restaurant->logoThumbUrl(),
            primaryColor: $restaurant->primary_color,
            secondaryColor: $restaurant->secondary_color,
            email: $restaurant->email,
            phone: $restaurant->phone,
            taxRatePercent: (float) $restaurant->tax_rate_percent,
            deliveryFeeCents: (int) $restaurant->delivery_fee_cents,
            isActive: (bool) $restaurant->is_active,
            createdAt: $restaurant->created_at?->toIso8601String(),
        );
    }
}
