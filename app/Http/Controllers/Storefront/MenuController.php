<?php

namespace App\Http\Controllers\Storefront;

use App\Data\ItemTemplateData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\ItemTemplate;
use App\Models\Restaurant;
use App\Support\Menus\StorefrontMenuQuery;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function __invoke(CurrentTenant $tenant, Request $request, StorefrontMenuQuery $menu): Response
    {
        $restaurant = $tenant->get();
        $user = $request->user();
        $canEditMenu = $user
            && ($user->isSuperAdmin() || $user->isRestaurantAdminAt($restaurant));

        $categories = $menu->categoryData($restaurant, includeHidden: (bool) $canEditMenu);

        return Inertia::render('Storefront/Menu', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'categories' => $categories,
            // Admin-only payload powering the inline edit UI. Withheld from
            // customers — they never see categories/templates props.
            'editor' => $canEditMenu
                ? fn () => [
                    'categories' => $this->categoryOptions($restaurant),
                    'templates' => $this->templateOptions($restaurant),
                ]
                : null,
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    protected function categoryOptions(Restaurant $restaurant): array
    {
        return $restaurant->menuCategories()
            ->orderBy('position')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function templateOptions(Restaurant $restaurant): array
    {
        return ItemTemplate::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->with('groups.options')
            ->orderBy('name')
            ->get()
            ->map(fn (ItemTemplate $t) => ItemTemplateData::fromModel($t)->toArray())
            ->all();
    }
}
