<?php

namespace App\Http\Controllers;

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Http\Requests\OwnerSignupRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OwnerSignupController extends Controller
{
    /**
     * Restaurant-owner marketing landing page.
     */
    public function landing(): Response
    {
        return Inertia::render('ForRestaurants/Landing');
    }

    /**
     * Owner signup form.
     */
    public function create(): Response
    {
        return Inertia::render('ForRestaurants/Signup', [
            'reservedSubdomains' => array_values((array) config('platform.reserved_subdomains', [])),
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
     */
    public function store(OwnerSignupRequest $request): RedirectResponse
    {
        [$user, $restaurant] = DB::transaction(function () use ($request) {
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

            return [$user, $restaurant];
        });

        Auth::login($user);

        return redirect()->route('admin.restaurant.onboarding.show', [
            'restaurant' => $restaurant->subdomain,
        ]);
    }
}
