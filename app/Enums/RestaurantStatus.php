<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum RestaurantStatus: string
{
    /**
     * The restaurant signup has been created but the platform has not yet
     * approved it. No storefront, not visible anywhere public.
     */
    case PendingReview = 'pending_review';

    /**
     * Approved by the platform. Owner can complete onboarding (menu, hours,
     * Stripe, billing). Still not visible on the public diner homepage.
     */
    case Approved = 'approved';

    /**
     * Onboarding complete and the storefront is live. Eligible to appear on
     * the public diner homepage when also `is_active = true`.
     */
    case Active = 'active';

    /**
     * Temporarily disabled by the platform (billing lapse, ToS violation,
     * etc.). Storefront returns 503 and the owner sees a reactivation banner.
     */
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }
}
