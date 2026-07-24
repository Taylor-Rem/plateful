<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\RestaurantImageService;
use Illuminate\Http\RedirectResponse;

/**
 * Restaurant lifecycle state: activation, soft delete, restore, and the
 * guarded permanent delete. CRUD and pricing stay in RestaurantsController.
 */
class RestaurantLifecycleController extends Controller
{
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
