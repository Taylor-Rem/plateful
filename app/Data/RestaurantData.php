<?php

namespace App\Data;

use App\Models\Restaurant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RestaurantData extends Data
{
    /**
     * @param  array<int, array<int, array{opensAt: string, closesAt: string, position: int}>>  $hoursByDay
     */
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
        public string $timezone,
        public bool $isOpen,
        public ?string $nextOpenLabel,
        public array $hoursByDay,
        public ?string $createdAt,
    ) {}

    public static function fromModel(Restaurant $restaurant): self
    {
        $hours = $restaurant->relationLoaded('hours')
            ? $restaurant->getRelation('hours')
            : $restaurant->hours()->get();

        $hoursByDay = [];
        for ($d = 0; $d < 7; $d++) {
            $hoursByDay[$d] = [];
        }
        foreach ($hours as $h) {
            $hoursByDay[(int) $h->day_of_week][] = [
                'opensAt' => substr((string) $h->opens_at, 0, 5),
                'closesAt' => substr((string) $h->closes_at, 0, 5),
                'position' => (int) $h->position,
            ];
        }
        foreach ($hoursByDay as $d => $windows) {
            usort($hoursByDay[$d], fn ($a, $b) => $a['position'] <=> $b['position']);
        }

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
            timezone: (string) ($restaurant->timezone ?: 'America/New_York'),
            isOpen: $restaurant->isOpenAt(),
            nextOpenLabel: $restaurant->formatNextOpenAt(),
            hoursByDay: $hoursByDay,
            createdAt: $restaurant->created_at?->toIso8601String(),
        );
    }
}
