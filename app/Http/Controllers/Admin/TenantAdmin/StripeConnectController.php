<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StripeConnectController extends Controller
{
    public function __construct(private StripeConnectService $connect) {}

    /**
     * Create the connected account if missing, then send the owner to a fresh
     * Stripe-hosted onboarding link.
     */
    public function start(Restaurant $restaurant): Response
    {
        $this->authorize('manageStripe', $restaurant);

        if (! $restaurant->hasStripeAccount()) {
            $this->connect->createExpressAccount($restaurant);
        }

        return Inertia::location($this->onboardingLink($restaurant));
    }

    /**
     * Stripe redirects here after onboarding. Refresh status from the API,
     * then return to the onboarding wizard.
     */
    public function return(Restaurant $restaurant): RedirectResponse
    {
        $this->authorize('manageStripe', $restaurant);

        if ($restaurant->hasStripeAccount()) {
            $account = $this->connect->retrieveAccount($restaurant->stripe_account_id);
            $this->connect->syncAccountStatus($restaurant, $account);
        }

        return redirect()
            ->route('admin.restaurant.onboarding.show', ['restaurant' => $restaurant->subdomain])
            ->with('success', $restaurant->fresh()->isStripeReady()
                ? 'Stripe is connected — you can take payments.'
                : 'Stripe onboarding saved. We’ll update the status once Stripe finishes reviewing.');
    }

    /**
     * Stripe redirects here if the onboarding link expired or the owner
     * refreshed mid-flow. Hand back a freshly generated link.
     */
    public function refresh(Restaurant $restaurant): Response
    {
        $this->authorize('manageStripe', $restaurant);

        if (! $restaurant->hasStripeAccount()) {
            $this->connect->createExpressAccount($restaurant);
        }

        return Inertia::location($this->onboardingLink($restaurant));
    }

    /**
     * Send the owner to the Express Dashboard to update bank info.
     */
    public function dashboard(Restaurant $restaurant): Response
    {
        $this->authorize('manageStripe', $restaurant);

        if (! $restaurant->hasStripeAccount()) {
            return back()->with('error', 'Connect Stripe before opening the dashboard.');
        }

        return Inertia::location($this->connect->createDashboardLink($restaurant));
    }

    private function onboardingLink(Restaurant $restaurant): string
    {
        return $this->connect->createAccountLink(
            $restaurant,
            route('admin.restaurant.onboarding.stripe.return', ['restaurant' => $restaurant->subdomain]),
            route('admin.restaurant.onboarding.stripe.refresh', ['restaurant' => $restaurant->subdomain]),
        );
    }
}
