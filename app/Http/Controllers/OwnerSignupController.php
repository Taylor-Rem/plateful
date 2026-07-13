<?php

namespace App\Http\Controllers;

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Http\Requests\OwnerSignupRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\StorefrontLoginHandoff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OwnerSignupController extends Controller
{
    /**
     * Restaurant-owner marketing landing page.
     *
     * Passes auth context so the page can greet a signed-in owner and offer a
     * jump to the admin console, or show sign-in/get-started to a visitor.
     */
    public function landing(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('ForRestaurants/Landing', [
            'authUserName' => $user?->name,
            'hasAdminAccess' => (bool) $user?->isAdmin(),
            'adminUrl' => $request->getScheme().'://admin.'.config('platform.primary_domain'),
        ]);
    }

    /**
     * Owner signup form. Kept to the five fields needed to create the account
     * and claim a storefront URL — everything else happens in the wizard.
     */
    public function create(): Response
    {
        return Inertia::render('ForRestaurants/Signup', [
            'primaryDomain' => config('platform.primary_domain'),
        ]);
    }

    /**
     * Self-serve signup. Creates the owner's Plateful account and their
     * restaurant in one transaction, grants them admin access via the
     * restaurant_user pivot, logs them in, and drops them straight into the
     * onboarding wizard. The restaurant is `approved` + `is_active` (it can be
     * configured) but NOT live — go-live still requires Stripe Connect plus the
     * required onboarding steps, so a dead signup costs the platform nothing.
     *
     * Sessions are host-scoped (SESSION_DOMAIN is null), so the login
     * established here on the primary host cannot cross to the admin host by
     * cookie. We mint a single-use handoff token and let the admin host
     * exchange it for a session of its own before landing on onboarding.
     */
    public function store(OwnerSignupRequest $request, StorefrontLoginHandoff $handoff): SymfonyResponse
    {
        [$user, $restaurant] = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->string('name')->trim()->toString(),
                'email' => $request->string('email')->trim()->toString(),
                'password' => $request->string('password')->toString(),
            ]);

            $restaurant = Restaurant::create([
                'name' => $request->string('restaurant_name')->trim()->toString(),
                'subdomain' => $request->string('subdomain')->toString(),
                'email' => $user->email,
                'street' => '',
                'city' => '',
                'state' => '',
                'postal_code' => '',
                'country' => 'US',
                'timezone' => $request->input('timezone') ?: 'America/New_York',
                'is_active' => true,
                'status' => RestaurantStatus::Approved,
                'approved_at' => now(),
            ]);

            // Owner becomes a restaurant admin via the pivot — this is the
            // moment they gain access to the admin console.
            $restaurant->members()->attach($user->id, [
                'role' => RestaurantRole::Admin->value,
            ]);

            return [$user, $restaurant];
        });

        // Log in on the primary host too, so returning to plateful.test
        // greets the owner by name.
        Auth::login($user);

        $adminHost = config('platform.admin_subdomain').'.'.config('platform.primary_domain');

        return Inertia::location(
            $request->getScheme().'://'.$adminHost.'/auth/handoff?'.http_build_query([
                'token' => $handoff->issue($user, $adminHost),
                'to' => "/{$restaurant->subdomain}/onboarding",
            ])
        );
    }
}
