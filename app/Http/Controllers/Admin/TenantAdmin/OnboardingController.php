<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Enums\RestaurantStatus;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Render the onboarding checklist for an approved restaurant. Each step
     * is computed from existing data so the UI always reflects reality.
     */
    public function show(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Onboarding', [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'subdomain' => $restaurant->subdomain,
                'status' => $restaurant->status?->value,
                'customDomain' => $restaurant->custom_domain,
                'pendingCustomDomain' => $restaurant->pending_custom_domain,
                'customDomainRequestedAt' => $restaurant->custom_domain_requested_at?->toIso8601String(),
                'onboardingCompletedAt' => $restaurant->onboarding_completed_at?->toIso8601String(),
                'isLive' => $restaurant->isLive(),
            ],
            'steps' => $this->steps($restaurant),
            'canGoLive' => $this->canGoLive($restaurant),
            'primaryDomain' => config('platform.primary_domain'),
        ]);
    }

    /**
     * Owner records a custom-domain request. We do NOT update `custom_domain`
     * directly — the platform configures DNS/TLS manually and flips it after
     * verification.
     */
    public function requestCustomDomain(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validate([
            'pending_custom_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ], [
            'pending_custom_domain.regex' => 'Enter a valid domain (e.g. pizzajoint.com).',
        ]);

        $restaurant->update([
            'pending_custom_domain' => strtolower(trim($validated['pending_custom_domain'])),
            'custom_domain_requested_at' => now(),
        ]);

        return back()->with('success', 'Custom domain request submitted. We’ll be in touch once DNS is set up.');
    }

    /**
     * Transition the restaurant from `approved` to `active` and mark
     * onboarding complete. Requires required steps to pass.
     */
    public function goLive(Restaurant $restaurant): RedirectResponse
    {
        if ($restaurant->status !== RestaurantStatus::Approved) {
            return back()->with('error', 'This restaurant is not in the approved state.');
        }

        if (! $this->canGoLive($restaurant)) {
            throw ValidationException::withMessages([
                'go_live' => 'Finish the required onboarding steps before going live.',
            ]);
        }

        $restaurant->update([
            'status' => RestaurantStatus::Active,
            'is_active' => true,
            'onboarding_completed_at' => now(),
        ]);

        return redirect()
            ->route('admin.restaurant.dashboard', ['restaurant' => $restaurant->subdomain])
            ->with('success', "{$restaurant->name} is live!");
    }

    /**
     * Compute the status of each onboarding step from the restaurant's data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function steps(Restaurant $restaurant): array
    {
        $hasHours = $restaurant->hours()->exists();
        $hasMenuItem = $restaurant->menuItems()->exists();
        $hasBranding = filled($restaurant->logo_path) || filled($restaurant->description);

        return [
            [
                'key' => 'basics',
                'title' => 'Restaurant basics',
                'description' => 'Logo, description, contact info, and address.',
                'href' => "/{$restaurant->subdomain}/settings",
                'complete' => $hasBranding,
                'required' => false,
            ],
            [
                'key' => 'hours',
                'title' => 'Set operating hours',
                'description' => 'Tell customers when you’re open.',
                'href' => "/{$restaurant->subdomain}/hours",
                'complete' => $hasHours,
                'required' => true,
            ],
            [
                'key' => 'menu',
                'title' => 'Add at least one menu item',
                'description' => 'Customers can’t order an empty menu.',
                'href' => "/{$restaurant->subdomain}/menu",
                'complete' => $hasMenuItem,
                'required' => true,
            ],
            [
                'key' => 'stripe',
                'title' => 'Connect Stripe',
                'description' => $this->stripeStepDescription($restaurant),
                'href' => "/{$restaurant->subdomain}/onboarding",
                'complete' => $restaurant->isStripeReady(),
                'required' => true,
                'stripeStatus' => $restaurant->stripe_account_status,
            ],
        ];
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
            default => 'Required to take payments. Plateful takes a 1% fee per order.',
        };
    }

    private function canGoLive(Restaurant $restaurant): bool
    {
        if ($restaurant->status !== RestaurantStatus::Approved) {
            return false;
        }

        foreach ($this->steps($restaurant) as $step) {
            if ($step['required'] && ! $step['complete']) {
                return false;
            }
        }

        return true;
    }
}
