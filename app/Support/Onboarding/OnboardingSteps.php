<?php

namespace App\Support\Onboarding;

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;

/**
 * Computes the setup wizard's step statuses from a restaurant's data. Shared
 * by the onboarding wizard and the dashboard's "finish setup" surface, so the
 * two can never disagree about what's left to do.
 */
class OnboardingSteps
{
    /**
     * The status of each wizard step, computed from existing data so the UI
     * always reflects reality.
     *
     * @return array<int, array{key: string, title: string, description: string, complete: bool, required: bool}>
     */
    public function steps(Restaurant $restaurant): array
    {
        $hasHours = $restaurant->hours()->exists();
        $hasMenuItem = $restaurant->menuItems()->exists();
        $hasBranding = filled($restaurant->logo_path) || filled($restaurant->description);

        return [
            [
                'key' => 'basics',
                'title' => 'Basics',
                'description' => 'Logo, description, contact info, and address.',
                'complete' => $hasBranding,
                'required' => false,
            ],
            [
                'key' => 'hours',
                'title' => 'Hours',
                'description' => 'Tell customers when you’re open.',
                'complete' => $hasHours,
                'required' => true,
            ],
            [
                'key' => 'menu',
                'title' => 'Menu',
                'description' => 'Customers can’t order from an empty menu.',
                'complete' => $hasMenuItem,
                'required' => true,
            ],
            [
                'key' => 'stripe',
                'title' => 'Payments',
                'description' => $this->stripeStepDescription($restaurant),
                'complete' => $restaurant->isStripeReady(),
                'required' => true,
            ],
            [
                'key' => 'refunds',
                'title' => 'Refund policy',
                'description' => 'Decide whether cancelled orders refund the food. Off by default.',
                'complete' => $restaurant->refund_policy_reviewed_at !== null,
                'required' => false,
            ],
            [
                'key' => 'review',
                'title' => 'Go live',
                'description' => 'Review everything and open for orders.',
                'complete' => $restaurant->isLive(),
                'required' => true,
            ],
        ];
    }

    public function canGoLive(Restaurant $restaurant): bool
    {
        if ($restaurant->status !== RestaurantStatus::Approved) {
            return false;
        }

        foreach ($this->steps($restaurant) as $step) {
            if ($step['key'] !== 'review' && $step['required'] && ! $step['complete']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The incomplete steps (excluding the go-live step itself) — what the
     * dashboard's setup surface lists.
     *
     * @return array<int, array{key: string, title: string, description: string, complete: bool, required: bool}>
     */
    public function remaining(Restaurant $restaurant): array
    {
        return array_values(array_filter(
            $this->steps($restaurant),
            fn (array $step) => $step['key'] !== 'review' && ! $step['complete'],
        ));
    }

    /**
     * Status-aware copy for the Stripe onboarding step.
     */
    private function stripeStepDescription(Restaurant $restaurant): string
    {
        return match ($restaurant->stripe_account_status) {
            Restaurant::STRIPE_ENABLED => 'Connected — you can take payments.',
            Restaurant::STRIPE_RESTRICTED => 'Stripe needs more information before you can take payments.',
            Restaurant::STRIPE_PENDING => 'Onboarding started. Finish it on Stripe to take payments.',
            default => 'Required to take payments. Plateful takes a 4% fee per order.',
        };
    }
}
