<?php

namespace App\Http\Controllers;

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Http\Requests\OwnerSignupRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Menus\MenuBuilder;
use App\Support\Menus\MenuPresets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * Owner signup form.
     */
    public function create(): Response
    {
        return Inertia::render('ForRestaurants/Signup', [
            'reservedSubdomains' => array_values((array) config('platform.reserved_subdomains', [])),
            'primaryDomain' => config('platform.primary_domain'),
            'menuPresets' => array_map(
                fn (string $cuisine): array => [
                    'value' => $cuisine,
                    'label' => Str::headline($cuisine),
                ],
                MenuPresets::cuisines(),
            ),
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
     * If the owner picked a starter-menu preset, we seed it now so they land in
     * onboarding with a menu already built (and editable).
     */
    public function store(OwnerSignupRequest $request, MenuBuilder $menuBuilder): SymfonyResponse
    {
        [$user, $restaurant] = DB::transaction(function () use ($request, $menuBuilder) {
            $user = User::create([
                'name' => $request->string('name')->trim()->toString(),
                'email' => $request->string('email')->trim()->toString(),
                'phone' => $request->input('phone'),
                'password' => $request->string('password')->toString(),
            ]);

            $restaurant = Restaurant::create([
                'name' => $request->string('restaurant_name')->trim()->toString(),
                'subdomain' => $request->string('subdomain')->toString(),
                'email' => $user->email,
                'phone' => $user->phone,
                'street' => '',
                'city' => $request->input('city') ?? '',
                'state' => $request->input('state') ?? '',
                'postal_code' => '',
                'country' => 'US',
                'timezone' => 'America/New_York',
                'is_active' => true,
                'status' => RestaurantStatus::Approved,
                'approved_at' => now(),
            ]);

            // Owner becomes a restaurant admin via the pivot — this is the
            // moment they gain access to the admin console.
            $restaurant->members()->attach($user->id, [
                'role' => RestaurantRole::Admin->value,
            ]);

            // Optional starter menu. Validation guarantees the preset is one of
            // MenuPresets::cuisines(), so a non-empty value is always buildable.
            $preset = $request->string('menu_preset')->toString();
            if ($preset !== '') {
                $menuBuilder->build($restaurant, $preset);
            }

            return [$user, $restaurant];
        });

        Auth::login($user);

        // Onboarding lives on the admin host — a different origin from this
        // tenant-root signup page. A normal redirect would be followed by
        // Inertia over XHR and blocked by CORS, so force a full-page visit.
        return Inertia::location(route('admin.restaurant.onboarding.show', [
            'restaurant' => $restaurant->subdomain,
        ]));
    }
}
