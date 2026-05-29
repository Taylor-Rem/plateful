<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Enums\RestaurantStatus;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class BillingController extends Controller
{
    public function show(Restaurant $restaurant): Response
    {
        $subscription = $restaurant->subscription(config('platform.billing.subscription_type', 'default'));

        $trialEndsAt = $restaurant->trial_ends_at;
        $hasSubscription = $subscription !== null;
        $isSubscribed = $restaurant->subscribed(config('platform.billing.subscription_type', 'default'));

        return Inertia::render('Admin/TenantAdmin/Billing', [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'subdomain' => $restaurant->subdomain,
                'status' => $restaurant->status?->value,
                'isSuspended' => $restaurant->status === RestaurantStatus::Suspended,
            ],
            'billing' => [
                'onTrial' => $restaurant->onGenericTrial(),
                'trialEndsAt' => $trialEndsAt?->toIso8601String(),
                'trialDaysLeft' => $trialEndsAt
                    ? max(0, (int) now()->diffInDays($trialEndsAt, false))
                    : null,
                'hasSubscription' => $hasSubscription,
                'isSubscribed' => $isSubscribed,
                'subscriptionStatus' => $subscription?->stripe_status,
                'subscriptionEndsAt' => $subscription?->ends_at?->toIso8601String(),
                'priceConfigured' => filled(config('platform.billing.stripe_price')),
            ],
        ]);
    }

    /**
     * Send the owner to a Stripe Checkout session for the platform's
     * subscription price. Cashier returns the redirect.
     */
    public function checkout(Request $request, Restaurant $restaurant): SymfonyRedirectResponse|RedirectResponse
    {
        $price = config('platform.billing.stripe_price');
        if (! $price) {
            return back()->with('error', 'Billing is not configured yet. Please contact support.');
        }

        // Already subscribed — send them to the customer portal instead.
        if ($restaurant->subscribed(config('platform.billing.subscription_type', 'default'))) {
            return $this->portal($request, $restaurant);
        }

        $successUrl = route('admin.restaurant.billing.show', ['restaurant' => $restaurant->subdomain]).'?checkout=success';
        $cancelUrl = route('admin.restaurant.billing.show', ['restaurant' => $restaurant->subdomain]).'?checkout=cancel';

        return $restaurant
            ->newSubscription(config('platform.billing.subscription_type', 'default'), $price)
            ->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]);
    }

    /**
     * Send the owner to Stripe's hosted billing portal to update card,
     * download invoices, or cancel.
     */
    public function portal(Request $request, Restaurant $restaurant): SymfonyRedirectResponse|RedirectResponse
    {
        if (! $restaurant->hasStripeId()) {
            return back()->with('error', 'No billing account on file yet.');
        }

        $returnUrl = route('admin.restaurant.billing.show', ['restaurant' => $restaurant->subdomain]);

        return $restaurant->redirectToBillingPortal($returnUrl);
    }
}
