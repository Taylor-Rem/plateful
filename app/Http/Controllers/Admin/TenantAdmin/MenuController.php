<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\ItemTemplateData;
use App\Data\MenuCategoryData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\ItemTemplate;
use App\Models\MenuImport;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function index(Restaurant $restaurant): Response
    {
        $categories = $restaurant->menuCategories()
            ->orderBy('position')
            ->with([
                'items' => fn ($q) => $q->orderBy('position'),
                'items.templates.groups.options',
                'items.ownGroups.options',
                'items.ingredients',
                'items.defaultSelections',
            ])
            ->get()
            ->map(fn ($c) => MenuCategoryData::fromModel($c))
            ->all();

        return Inertia::render('Admin/TenantAdmin/Menu', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'categories' => $categories,
            // Swap sets for the Ingredients panel (it filters to single
            // pick-one-group templates client-side).
            'templates' => ItemTemplate::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)
                ->with('groups.options')
                ->orderBy('name')
                ->get()
                ->map(fn (ItemTemplate $t) => ItemTemplateData::fromModel($t))
                ->all(),
            // The re-import card polls this while an extraction runs.
            'menuImport' => MenuImport::activeStateFor($restaurant),
            'menuImportLimits' => [
                'maxFiles' => (int) config('menu_import.max_files'),
                'maxFileKb' => (int) config('menu_import.max_file_kb'),
            ],
        ]);
    }
}
