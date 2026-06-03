<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Data\AdminUserData;
use App\Data\PendingInvitationData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuperAdmin\StoreRestaurantRequest;
use App\Http\Requests\Admin\SuperAdmin\UpdateRestaurantFeeRequest;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantsController extends Controller
{
    public function index(): Response
    {
        $restaurants = Restaurant::query()
            ->withCount('admins')
            ->orderBy('name')
            ->get()
            ->map(fn (Restaurant $r) => [
                ...RestaurantData::fromModel($r)->toArray(),
                'adminsCount' => (int) $r->admins_count,
            ])
            ->all();

        return Inertia::render('Admin/SuperAdmin/Restaurants/Index', [
            'restaurants' => $restaurants,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/SuperAdmin/Restaurants/Create', [
            'timezones' => array_values((array) config('platform.timezones', [])),
            'reservedSubdomains' => array_values((array) config('platform.reserved_subdomains', [])),
            'primaryDomain' => config('platform.primary_domain'),
        ]);
    }

    public function store(StoreRestaurantRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $restaurant = Restaurant::create([
            'name' => $validated['name'],
            'subdomain' => $validated['subdomain'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'street' => $validated['street'] ?? '',
            'street2' => $validated['street2'] ?? null,
            'city' => $validated['city'] ?? '',
            'state' => $validated['state'] ?? '',
            'postal_code' => $validated['postal_code'] ?? '',
            'country' => $validated['country'] ?? 'US',
            'timezone' => $validated['timezone'],
            'primary_color' => $validated['primary_color'] ?? null,
            'secondary_color' => $validated['secondary_color'] ?? null,
            'description' => $validated['description'] ?? null,
            'tax_rate_percent' => $validated['tax_rate_percent'] ?? 0,
            'delivery_fee_cents' => $request->input('delivery_fee_cents', 0),
            'is_active' => true,
        ]);

        if (! empty($validated['owner_email'])) {
            $invitation = AdminInvitation::create([
                'email' => $validated['owner_email'],
                'restaurant_id' => $restaurant->id,
                'as_super_admin' => false,
                'token' => AdminInvitation::generateToken(),
                'invited_by_user_id' => $request->user()->id,
                'expires_at' => now()->addDays(7),
            ]);

            Mail::to($invitation->email)->queue(new AdminInvitationMail($invitation));
        }

        return redirect()
            ->route('admin.super.restaurants.show', $restaurant)
            ->with('success', "Restaurant {$restaurant->name} created.");
    }

    public function show(Restaurant $restaurant): Response
    {
        $admins = $restaurant->admins()->orderBy('name')->get()
            ->map(fn ($user) => AdminUserData::fromModel($user))
            ->all();

        $pendingInvitations = AdminInvitation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('invitedBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($i) => PendingInvitationData::fromModel($i))
            ->all();

        return Inertia::render('Admin/SuperAdmin/Restaurants/Show', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'admins' => $admins,
            'pendingInvitations' => $pendingInvitations,
        ]);
    }

    /**
     * Set this restaurant's per-restaurant application fee rate (an override).
     * This is PLATFORM pricing — gated to super admins by the `super`
     * middleware — and only ever touches this single restaurant, so existing
     * restaurants stay locked at their stored rate.
     */
    public function updateFee(UpdateRestaurantFeeRequest $request, Restaurant $restaurant): RedirectResponse
    {
        // application_fee_percent is intentionally not mass-assignable; set it
        // explicitly from the validated input.
        $restaurant->application_fee_percent = $request->validated()['application_fee_percent'];
        $restaurant->save();

        return redirect()
            ->route('admin.super.restaurants.show', $restaurant)
            ->with('success', "Updated {$restaurant->name}'s fee rate to {$restaurant->application_fee_percent}%.");
    }

    public function deactivate(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->update(['is_active' => false]);

        return redirect()
            ->route('admin.super.restaurants.show', $restaurant)
            ->with('success', "{$restaurant->name} has been deactivated.");
    }

    public function activate(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->update(['is_active' => true]);

        return redirect()
            ->route('admin.super.restaurants.show', $restaurant)
            ->with('success', "{$restaurant->name} has been reactivated.");
    }
}
