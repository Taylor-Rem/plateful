<?php

namespace App\Http\Controllers\Storefront;

use App\Data\RestaurantData;
use App\Enums\MarketingConsentSource;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\MarketingConsentService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Login-free, signed-URL unsubscribe (campaigns plan). Built ahead of any
 * sending: the CSV consent columns and the account toggle reference it, and it
 * must exist before the first campaign goes out. The signature is validated
 * relative (storefronts answer on subdomains and custom domains); the
 * restaurant id inside the signed params is pinned to the current tenant so a
 * link only ever acts on the restaurant it was issued for.
 */
class MarketingUnsubscribeController extends Controller
{
    /**
     * One click → opted out, with an undo. GET on purpose: this is the target
     * of an email link, and it must work logged-out in any mail client.
     */
    public function show(Request $request, CurrentTenant $tenant, MarketingConsentService $consent): Response
    {
        [$user, $restaurant] = $this->resolveSubject($request, $tenant);

        $consent->optOutEmail($user, $restaurant, MarketingConsentSource::UnsubscribeLink, $request->ip(), $request->userAgent());

        return Inertia::render('Storefront/MarketingUnsubscribed', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'email' => $user->email,
            'resubscribeUrl' => URL::signedRoute('storefront.marketing.resubscribe', [
                'user' => $user->id,
                'restaurant' => $restaurant->id,
            ], absolute: false),
        ]);
    }

    /**
     * The undo on the confirmation page.
     */
    public function resubscribe(Request $request, CurrentTenant $tenant, MarketingConsentService $consent): RedirectResponse
    {
        [$user, $restaurant] = $this->resolveSubject($request, $tenant);

        $consent->optInEmail($user, $restaurant, MarketingConsentSource::UnsubscribeLink, $request->ip(), $request->userAgent());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You\'re subscribed to :name emails again.', ['name' => $restaurant->name]),
        ]);

        return to_route('storefront.home');
    }

    /**
     * @return array{0: User, 1: Restaurant}
     */
    protected function resolveSubject(Request $request, CurrentTenant $tenant): array
    {
        $restaurant = $tenant->get();

        if ((int) $request->query('restaurant') !== $restaurant->id) {
            throw new NotFoundHttpException;
        }

        $user = User::query()->findOrFail((int) $request->query('user'));

        return [$user, $restaurant];
    }
}
