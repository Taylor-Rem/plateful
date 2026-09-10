<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\MenuExtractionService;
use App\Support\Menus\ExtractedMenuSanitizer;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * "Suggest customizations" for one hand-made item: a small Claude call that
 * returns printed ingredients plus proposals, flashed back to the
 * Ingredients panel (which shows them as switched-off suggestions until the
 * owner accepts each one). Registered on both hosts; see
 * MenuItemIngredientController for the `InConsole` convention.
 */
class MenuItemSuggestionController extends Controller
{
    public function storeInConsole(Restaurant $restaurant, MenuItem $menuItem, MenuExtractionService $extraction): RedirectResponse
    {
        return $this->store($menuItem, $extraction);
    }

    public function store(MenuItem $menuItem, MenuExtractionService $extraction): RedirectResponse
    {
        $tenant = app(CurrentTenant::class);
        abort_unless($menuItem->restaurant_id === $tenant->id(), 404);
        $this->authorize('update', $menuItem);

        $menuItem->loadMissing('category');

        try {
            $result = $extraction->suggestCustomizations(
                $menuItem->name,
                $menuItem->description,
                $menuItem->category?->name,
                $tenant->get()?->name,
            );
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not get suggestions right now — try again in a moment.');
        }

        return back()->with('itemSuggestions', [
            'menuItemId' => $menuItem->id,
            'ingredients' => ExtractedMenuSanitizer::sanitizeIngredientNames($result['ingredients']),
            'suggestions' => ExtractedMenuSanitizer::sanitizeSuggestions($result['suggested_customizations']),
        ]);
    }
}
