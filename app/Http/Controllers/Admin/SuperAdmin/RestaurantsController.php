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
use App\Models\AdminInvitation;
use App\Models\PlatformRoleHolder;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\AdminInvitationService;
use App\Services\RestaurantImageService;
use App\Services\RevenueSplitResolver;
use App\Support\SalesTaxRates;
use Illuminate\Http\RedirectResponse;
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

        // Soft-deleted restaurants drop off the main list but surface in their own
        // section so a super admin can restore or permanently delete them.
        $deletedRestaurants = Restaurant::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Restaurant $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'subdomain' => $r->subdomain,
                'deletedAt' => $r->deleted_at?->toIso8601String(),
                'ordersCount' => (int) $r->orders()->count(),
            ])
            ->all();

        return Inertia::render('Admin/SuperAdmin/Restaurants/Index', [
            'restaurants' => $restaurants,
            'deletedRestaurants' => $deletedRestaurants,
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

    public function store(StoreRestaurantRequest $request, AdminInvitationService $invitations): RedirectResponse
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
            // Left blank means "we don't know yet", not 0% — seed the location
            // estimate so a skipped onboarding step can't leave a live
            // storefront charging no tax. An explicit 0 is still honoured.
            'tax_rate_percent' => $validated['tax_rate_percent']
                ?? SalesTaxRates::estimateFor($validated['state'] ?? null)
                ?? 0,
            'delivery_fee_cents' => $request->input('delivery_fee_cents', 0),
            'is_active' => true,
        ]);

        if (! empty($validated['owner_email'])) {
            $invitations->send(
                email: $validated['owner_email'],
                restaurant: $restaurant,
                role: null,
                asSuperAdmin: false,
                invitedBy: $request->user(),
            );
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

    /**
     * Soft delete: the restaurant drops off the roster and its storefront and
     * admin routes 404 (the tenant resolver and route binding both query without
     * trashed rows), but every record is retained and can be restored.
     */
    public function destroy(Restaurant $restaurant): RedirectResponse
    {
        $name = $restaurant->name;
        $restaurant->delete();

        return redirect()
            ->route('admin.super.restaurants.index')
            ->with('success', "{$name} has been deleted. You can restore it from the deleted list.");
    }

    public function restore(string $subdomain): RedirectResponse
    {
        $restaurant = Restaurant::onlyTrashed()->where('subdomain', $subdomain)->firstOrFail();
        $restaurant->restore();

        return redirect()
            ->route('admin.super.restaurants.show', $restaurant)
            ->with('success', "{$restaurant->name} has been restored.");
    }

    /**
     * Permanent delete. Guarded: a restaurant that has ever taken an order keeps
     * financial and audit history we must not destroy, so hard delete is refused
     * and the super admin is left with the (reversible) soft delete. With no
     * orders, the cascade FKs clear the remaining rows and we drop the image dir.
     */
    public function forceDelete(string $subdomain, RestaurantImageService $images): RedirectResponse
    {
        $restaurant = Restaurant::onlyTrashed()->where('subdomain', $subdomain)->firstOrFail();

        if ($restaurant->orders()->exists()) {
            return redirect()
                ->route('admin.super.restaurants.index')
                ->with('error', "{$restaurant->name} has order history and can't be permanently deleted. It stays soft-deleted so its records are preserved.");
        }

        $name = $restaurant->name;
        $images->deleteDirectoryForRestaurant($restaurant);
        $restaurant->forceDelete();

        return redirect()
            ->route('admin.super.restaurants.index')
            ->with('success', "{$name} has been permanently deleted.");
    }
}
