<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Data\AdminUserData;
use App\Data\PendingInvitationData;
use App\Data\RestaurantData;
use App\Enums\RevenueRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuperAdmin\StoreRestaurantRequest;
use App\Http\Requests\Admin\SuperAdmin\UpdateRestaurantFeeRequest;
use App\Http\Requests\Admin\SuperAdmin\UpdateRestaurantRolesRequest;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\PlatformRoleHolder;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RevenueSplitResolver;
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
            'revenueRoles' => $this->revenueRolesFor($restaurant),
            'assignableUsers' => $this->assignableUsers($restaurant),
        ]);
    }

    /**
     * Snapshot of how this restaurant's retained fee splits: the share config,
     * each role's currently resolved earner, and the per-restaurant recruiter /
     * overseer assignments the form edits.
     *
     * @return array<string, mixed>
     */
    private function revenueRolesFor(Restaurant $restaurant): array
    {
        $operator = PlatformRoleHolder::holder(RevenueRole::Operator);

        $person = fn (?User $u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;

        return [
            'shares' => app(RevenueSplitResolver::class)->shares(),
            'recruiterId' => $restaurant->recruiter_id,
            'overseerId' => $restaurant->overseer_id,
            'resolved' => [
                'founder' => $person(PlatformRoleHolder::holder(RevenueRole::Founder)),
                'operator' => $person($operator),
                'recruiter' => $person($restaurant->recruiter),
                // A blank overseer is covered by the platform Operator.
                'overseer' => $person($restaurant->overseer ?? $operator),
                'overseerIsFallback' => $restaurant->overseer_id === null,
            ],
        ];
    }

    /**
     * Users who may be assigned as recruiter/overseer: platform staff (super
     * admins) plus anyone already holding one of those roles here.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function assignableUsers(Restaurant $restaurant): array
    {
        return User::query()
            ->where('is_super_admin', true)
            ->orWhereIn('id', array_filter([$restaurant->recruiter_id, $restaurant->overseer_id]))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->all();
    }

    /**
     * Assign the per-restaurant recruiter and overseer. These are attribution
     * only — they drive the earnings ledger and do NOT grant panel access,
     * which stays with the existing membership / super-admin mechanisms.
     */
    public function updateRoles(UpdateRestaurantRolesRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validated();

        $restaurant->recruiter_id = $validated['recruiter_id'] ?? null;
        $restaurant->overseer_id = $validated['overseer_id'] ?? null;
        $restaurant->save();

        return redirect()
            ->route('admin.super.restaurants.show', $restaurant)
            ->with('success', 'Revenue roles updated.');
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
