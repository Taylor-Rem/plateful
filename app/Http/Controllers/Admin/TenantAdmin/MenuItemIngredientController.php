<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplyIngredientRulesRequest;
use App\Http\Requests\Admin\MenuItemIngredientsRequest;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Support\Menus\IngredientEditor;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;

/**
 * Registered on both hosts: the storefront's edit mode (tenant host,
 * `/admin/menu/...`) and the admin console (`/{subdomain}/menu/...`). Both
 * set {@see CurrentTenant} before the route runs, so the tenant-scoped
 * bindings and the policy work the same way in either place. The console
 * routes carry a `{restaurant}` parameter ahead of the item, and Laravel
 * fills route parameters positionally — hence the `*InConsole` variants.
 */
class MenuItemIngredientController extends Controller
{
    public function updateInConsole(
        MenuItemIngredientsRequest $request,
        Restaurant $restaurant,
        MenuItem $menuItem,
        IngredientEditor $editor,
    ): RedirectResponse {
        return $this->update($request, $menuItem, $editor);
    }

    public function applyToCategoryInConsole(
        ApplyIngredientRulesRequest $request,
        Restaurant $restaurant,
        MenuCategory $category,
        CurrentTenant $tenant,
        IngredientEditor $editor,
    ): RedirectResponse {
        return $this->applyToCategory($request, $category, $tenant, $editor);
    }

    public function update(
        MenuItemIngredientsRequest $request,
        MenuItem $menuItem,
        IngredientEditor $editor,
    ): RedirectResponse {
        // Route bindings resolve before the tenant middleware runs, so an id
        // from another restaurant can reach here; treat it as not found
        // rather than leaking its existence through a 403.
        abort_unless($menuItem->restaurant_id === app(CurrentTenant::class)->id(), 404);
        $this->authorize('update', $menuItem);

        $editor->sync($menuItem, $request->rows());

        return back()->with('success', "Saved ingredients for \"{$menuItem->name}\".");
    }

    public function applyToCategory(
        ApplyIngredientRulesRequest $request,
        MenuCategory $category,
        CurrentTenant $tenant,
        IngredientEditor $editor,
    ): RedirectResponse {
        abort_unless($category->restaurant_id === $tenant->id(), 404);
        $this->authorize('create', [MenuItem::class, $tenant->get()]);

        $touched = $editor->applyRules($category, $request->rows());

        return back()->with(
            'success',
            $touched === 0
                ? "No other items in \"{$category->name}\" share those ingredients."
                : "Applied to {$touched} item".($touched === 1 ? '' : 's')." in \"{$category->name}\".",
        );
    }
}
