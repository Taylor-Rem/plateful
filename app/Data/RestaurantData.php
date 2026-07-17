<?php

namespace App\Data;

use App\Enums\DeliveryMode;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Request;
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
        public ?string $heroImageUrl,
        public ?string $heroImageMediumUrl,
        public ?string $heroTagline,
        public ?string $heroCtaLabel,
        public ?string $heroCtaUrl,
        public ?string $aboutBody,
        public ?string $aboutImageUrl,
        public ?string $aboutImageMediumUrl,
        public ?string $primaryColor,
        public ?string $secondaryColor,
        public ?string $email,
        public ?string $phone,
        public ?string $street,
        public ?string $street2,
        public ?string $city,
        public ?string $state,
        public ?string $postalCode,
        public float $taxRatePercent,
        public float $applicationFeePercent,
        public int $deliveryFeeCents,
        /** Refund the food on a cancelled PICKUP order (default off). */
        public bool $pickupRefundsEnabled,
        /** Refund the food on a cancelled DELIVERY order (default off). */
        public bool $deliveryRefundsEnabled,
        public bool $deliveryEnabled,
        /** Own drivers rather than a courier network — no quote, and a Tips Act disclaimer. */
        public bool $selfDelivery,
        public bool $isActive,
        public bool $isLive,
        public bool $isStripeReady,
        public string $timezone,
        public bool $isOpen,
        public ?string $nextOpenLabel,
        public ?string $openStatusLabel,
        /** @var array<string, string> */
        public array $socialLinks,
        public array $hoursByDay,
        public ?string $createdAt,
        public string $publicUrl,
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
            heroImageUrl: $restaurant->heroImageUrl(),
            heroImageMediumUrl: $restaurant->heroImageMediumUrl(),
            heroTagline: $restaurant->hero_tagline,
            heroCtaLabel: $restaurant->hero_cta_label,
            heroCtaUrl: $restaurant->hero_cta_url,
            aboutBody: $restaurant->about_body,
            aboutImageUrl: $restaurant->aboutImageUrl(),
            aboutImageMediumUrl: $restaurant->aboutImageMediumUrl(),
            primaryColor: $restaurant->primary_color,
            secondaryColor: $restaurant->secondary_color,
            email: $restaurant->email,
            phone: $restaurant->phone,
            street: $restaurant->street,
            street2: $restaurant->street2,
            city: $restaurant->city,
            state: $restaurant->state,
            postalCode: $restaurant->postal_code,
            taxRatePercent: (float) $restaurant->tax_rate_percent,
            applicationFeePercent: (float) $restaurant->application_fee_percent,
            deliveryFeeCents: (int) $restaurant->delivery_fee_cents,
            pickupRefundsEnabled: (bool) $restaurant->pickup_refunds_enabled,
            deliveryRefundsEnabled: (bool) $restaurant->delivery_refunds_enabled,
            deliveryEnabled: (bool) $restaurant->delivery_enabled,
            selfDelivery: $restaurant->delivery_mode === DeliveryMode::SelfDelivery,
            isActive: (bool) $restaurant->is_active,
            isLive: $restaurant->isLive(),
            isStripeReady: $restaurant->isStripeReady(),
            timezone: (string) ($restaurant->timezone ?: 'America/New_York'),
            isOpen: $restaurant->isOpenAt(),
            nextOpenLabel: $restaurant->formatNextOpenAt(),
            openStatusLabel: $restaurant->formatOpenStatus(),
            socialLinks: $restaurant->socialUrls(),
            hoursByDay: $hoursByDay,
            createdAt: $restaurant->created_at?->toIso8601String(),
            publicUrl: $restaurant->publicUrl(Request::getScheme() ?: 'https'),
        );
    }
}
