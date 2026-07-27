<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform-wide account roster: every user, whatever their standing —
 * super admins, restaurant staff, customers, and orphans with no access at all.
 * This controller owns account *lifecycle* (soft delete, restore, permanent
 * delete); AdminsController stays focused on admin *access*, the same way
 * RestaurantLifecycleController splits from RestaurantsController.
 */
class UsersController extends Controller
{
    private const FILTERS = ['all', 'admins', 'customers', 'deleted'];

    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filter = (string) $request->input('filter', 'all');

        if (! in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        $search = trim((string) $request->input('search', ''));

        $paginator = $this->baseQuery($filter, $search)
            ->with(['restaurants' => fn ($q) => $q->withCount('admins')])
            ->withCount(['restaurants', 'customerRestaurants', 'orders'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $currentUserId = $request->user()->id;
        $liveSuperAdmins = User::query()->where('is_super_admin', true)->count();

        return Inertia::render('Admin/SuperAdmin/Users/Index', [
            'users' => collect($paginator->items())
                ->map(fn (User $user) => $this->rowFor($user, $currentUserId, $liveSuperAdmins))
                ->values()
                ->all(),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'filter' => $filter,
                'search' => $search,
            ],
            'filterCounts' => [
                'all' => $this->baseQuery('all', $search)->count(),
                'admins' => $this->baseQuery('admins', $search)->count(),
                'customers' => $this->baseQuery('customers', $search)->count(),
                'deleted' => $this->baseQuery('deleted', $search)->count(),
            ],
        ]);
    }

    /**
     * The account's footprint, so a super admin never deletes blind. Resolved
     * withTrashed(): a deleted account still needs a page to restore from.
     */
    public function show(Request $request, string $id): Response
    {
        $user = User::withTrashed()
            ->with(['restaurants' => fn ($q) => $q->withCount('admins')])
            ->withCount(['restaurants', 'customerRestaurants', 'orders', 'addresses'])
            ->findOrFail($id);

        $orderStats = $user->orders()
            ->selectRaw('count(*) as orders_total, coalesce(sum(total_cents), 0) as spend_cents, max(placed_at) as last_placed_at')
            ->first();

        $customerRestaurants = $user->customerRestaurants()
            ->withTrashed()
            ->get()
            ->map(fn (Restaurant $restaurant) => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'subdomain' => $restaurant->subdomain,
                'totalOrders' => (int) $restaurant->pivot->total_orders,
                'totalSpentCents' => (int) $restaurant->pivot->total_spent_cents,
                'lastOrderedAt' => $this->iso($restaurant->pivot->last_ordered_at),
            ])
            ->all();

        return Inertia::render('Admin/SuperAdmin/Users/Show', [
            'user' => $this->rowFor(
                $user,
                $request->user()->id,
                User::query()->where('is_super_admin', true)->count(),
            ),
            'impact' => [
                'ordersCount' => (int) ($orderStats?->orders_total ?? 0),
                'lifetimeSpendCents' => (int) ($orderStats?->spend_cents ?? 0),
                'lastOrderAt' => $this->iso($orderStats?->last_placed_at),
                'loyaltyPoints' => (int) $user->loyaltyPoints()->sum('points'),
                'feeDistributionsCount' => $user->feeDistributions()->count(),
                'addressesCount' => $user->addresses_count,
                'customerRestaurants' => $customerRestaurants,
            ],
            'account' => [
                'phone' => $user->phone,
                'emailVerifiedAt' => $this->iso($user->email_verified_at),
                'hasPassword' => $user->password !== null,
                'hasGoogleLink' => $user->google_id !== null,
                'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
                'updatedAt' => $this->iso($user->updated_at),
            ],
        ]);
    }

    /**
     * Soft delete: the account stops existing for every live query — it can't
     * sign in, and the partial unique indexes free its email and Google link for
     * a new account — while every record it owns is retained for a restore.
     * Restaurant pivots are deliberately left attached so a restore is faithful.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($blocked = $this->deleteBlockedReason($user, $request->user()->id)) {
            return back()->with('error', $blocked);
        }

        $name = $user->name;
        $user->delete();

        return redirect()
            ->route('admin.super.users.index')
            ->with('success', "{$name}'s account was deleted. You can restore it from the Deleted filter.");
    }

    /**
     * Restore is the one genuinely new failure mode the partial unique indexes
     * introduce: while this account sat trashed, a *live* account may have
     * claimed its email or Google link. Restoring would then put two live rows
     * on one identifier, so we refuse instead of hitting a constraint violation.
     */
    public function restore(string $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);

        if (User::query()->where('email', $user->email)->exists()) {
            return back()->with('error', "Can't restore this account — {$user->email} now belongs to another account.");
        }

        if ($user->google_id !== null && User::query()->where('google_id', $user->google_id)->exists()) {
            return back()->with('error', "Can't restore this account — its Google login is now linked to another account.");
        }

        $user->restore();

        return redirect()
            ->route('admin.super.users.show', $user->id)
            ->with('success', "{$user->name}'s account has been restored.");
    }

    /**
     * Permanent delete. Guarded the same way a restaurant's is: an account that
     * has ever placed an order anchors financial and audit history we must not
     * destroy, so the hard delete is refused and it stays soft-deleted.
     */
    public function forceDelete(string $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);

        if ($user->orders()->exists()) {
            return redirect()
                ->route('admin.super.users.index')
                ->with('error', "{$user->name} has order history and can't be permanently deleted. The account stays deleted so its records are preserved.");
        }

        if ($user->feeDistributions()->exists()) {
            return redirect()
                ->route('admin.super.users.index')
                ->with('error', "{$user->name} has platform earnings attributed to them and can't be permanently deleted. The account stays deleted so its records are preserved.");
        }

        $name = $user->name;
        $user->forceDelete();

        return redirect()
            ->route('admin.super.users.index')
            ->with('success', "{$name}'s account has been permanently deleted.");
    }

    /**
     * @return Builder<User>
     */
    private function baseQuery(string $filter, string $search): Builder
    {
        return User::query()
            ->when($filter === 'deleted', fn (Builder $q) => $q->onlyTrashed())
            ->when($filter === 'admins', fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->where('is_super_admin', true)->orWhereHas('restaurants')
            ))
            ->when($filter === 'customers', fn (Builder $q) => $q->whereHas('customerRestaurants'))
            ->when($search !== '', function (Builder $q) use ($search): void {
                // lower() rather than ILIKE so the same query works on Postgres
                // (dev/prod) and SQLite (tests).
                $term = '%'.mb_strtolower($search).'%';
                $q->where(function (Builder $w) use ($term): void {
                    $w->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(email) like ?', [$term]);
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(User $user, int $currentUserId, int $liveSuperAdmins): array
    {
        $restaurants = $user->restaurants->map(fn (Restaurant $restaurant) => [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'subdomain' => $restaurant->subdomain,
            'role' => (string) $restaurant->pivot->role,
            // True when removing this person would leave the restaurant with no
            // admin at all — surfaced as a warning in the delete dialog.
            'isSoleAdmin' => (string) $restaurant->pivot->role === 'admin'
                && (int) $restaurant->admins_count <= 1,
        ])->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'isSuperAdmin' => $user->isSuperAdmin(),
            'type' => $this->typeFor($user),
            'restaurants' => $restaurants,
            'restaurantsCount' => (int) ($user->restaurants_count ?? count($restaurants)),
            'customerRestaurantsCount' => (int) ($user->customer_restaurants_count ?? 0),
            'ordersCount' => (int) ($user->orders_count ?? 0),
            'isDeleted' => $user->trashed(),
            'deletedAt' => $this->iso($user->deleted_at),
            'createdAt' => $this->iso($user->created_at),
            'deleteBlockedReason' => $this->deleteBlockedReason($user, $currentUserId, $liveSuperAdmins),
        ];
    }

    private function typeFor(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return 'super';
        }

        if ((int) ($user->restaurants_count ?? 0) > 0) {
            return 'admin';
        }

        if ((int) ($user->customer_restaurants_count ?? 0) > 0) {
            return 'customer';
        }

        return 'orphan';
    }

    /**
     * The reason this account can't be soft-deleted, or null when it can. Shared
     * by the server guard and the UI so a blocked row explains itself instead of
     * only failing on submit.
     */
    private function deleteBlockedReason(User $user, int $currentUserId, ?int $liveSuperAdmins = null): ?string
    {
        if ($user->trashed()) {
            return null;
        }

        if ($user->id === $currentUserId) {
            return "You can't delete your own account here.";
        }

        $isLastSuperAdmin = $liveSuperAdmins === null
            ? $user->isLastSuperAdmin()
            : $user->isSuperAdmin() && $liveSuperAdmins <= 1;

        if ($isLastSuperAdmin) {
            return "You can't delete the last super admin.";
        }

        return null;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format(\DateTimeInterface::ATOM)
            : (string) $value;
    }
}
