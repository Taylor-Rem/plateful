<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\MarketingConsentSource;
use App\Http\Controllers\Controller;
use App\Services\MarketingConsentService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MarketingController extends Controller
{
    /**
     * Per-restaurant marketing email toggle on the account profile page.
     */
    public function update(Request $request, CurrentTenant $tenant, MarketingConsentService $consent): RedirectResponse
    {
        $validated = $request->validate([
            'opted_in' => ['required', 'boolean'],
        ]);

        $restaurant = $tenant->get();
        $user = $request->user();

        if ($validated['opted_in']) {
            $consent->optInEmail($user, $restaurant, MarketingConsentSource::Account, $request->ip(), $request->userAgent());
            $message = __('You\'re subscribed to :name emails.', ['name' => $restaurant->name]);
        } else {
            $consent->optOutEmail($user, $restaurant, MarketingConsentSource::Account, $request->ip(), $request->userAgent());
            $message = __('You\'ve been unsubscribed from :name emails.', ['name' => $restaurant->name]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('storefront.account.profile.edit');
    }
}
