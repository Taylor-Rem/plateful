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
        /** Human-readable phone, e.g. "(435) 901-7141". */
        public ?string $phoneDisplay,
        /** tel: URI for the phone number. */
        public ?string $phoneHref,
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
        /** Whether the home page renders an #about section for visitors. */
        public bool $hasAboutSection,
        /** Whether the home page renders a #gallery section for visitors. */
        public bool $hasGalleryPhotos,
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
            phoneDisplay: self::formatPhone($restaurant->phone),
            phoneHref: self::phoneHref($restaurant->phone),
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
            hasAboutSection: trim((string) $restaurant->about_body) !== '' || $restaurant->aboutImageUrl() !== null,
            hasGalleryPhotos: $restaurant->relationLoaded('photos')
                ? $restaurant->getRelation('photos')->isNotEmpty()
                : $restaurant->photos()->exists(),
            createdAt: $restaurant->created_at?->toIso8601String(),
            publicUrl: $restaurant->publicUrl(Request::getScheme() ?: 'https'),
        );
    }

    /**
     * Format a stored phone number for display. US 10-digit numbers (with or
     * without a leading 1) become "(435) 901-7141"; anything else is returned
     * as stored.
     */
    public static function formatPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return $phone;
        }

        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }

    /**
     * Build a tel: URI for a stored phone number, preserving an existing
     * international prefix and assuming +1 for bare US 10-digit numbers.
     */
    public static function phoneHref(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        if (str_starts_with(trim($phone), '+')) {
            $digits = preg_replace('/\D/', '', $phone) ?? '';

            return $digits === '' ? null : 'tel:+'.$digits;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return 'tel:+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return 'tel:+'.$digits;
        }

        return 'tel:'.$digits;
    }
}
