<?php

namespace App\Http\Middleware;

use App\Data\CartData;
use App\Services\CartManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'canEditMenu' => fn () => $this->resolveCanEditMenu($request),
                'canEditSite' => fn () => $this->resolveCanEditMenu($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'cart' => fn () => $this->resolveCart(),
            'currentRestaurantRole' => fn () => $this->resolveCurrentRestaurantRole($request),
        ];
    }

    /**
     * True when the current user can edit menu content on the current tenant
     * storefront (restaurant Admin or super admin). False off the storefront.
     */
    protected function resolveCanEditMenu(Request $request): bool
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();

        if (! $tenant || ! $user) {
            return false;
        }

        return $user->isSuperAdmin() || $user->isRestaurantAdminAt($tenant);
    }

    protected function resolveCurrentRestaurantRole(Request $request): ?string
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();

        if (! $tenant || ! $user) {
            return null;
        }

        return $user->roleAt($tenant)?->value;
    }

    protected function resolveCart(): ?CartData
    {
        $tenant = app(CurrentTenant::class);
        if (! $tenant->check()) {
            return null;
        }

        $cart = app(CartManager::class)->current();
        if (! $cart) {
            return null;
        }

        return CartData::fromModel($cart);
    }
}
