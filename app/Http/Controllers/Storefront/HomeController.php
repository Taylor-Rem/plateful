<?php

namespace App\Http\Controllers\Storefront;

use App\Data\ItemTemplateData;
use App\Data\MenuCategoryData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\ItemTemplate;
use App\Models\Restaurant;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(CurrentTenant $tenant, Request $request): Response
    {
        $restaurant = $tenant->get();
        $user = $request->user();
        $canEditMenu = $user
            && ($user->isSuperAdmin() || $user->isRestaurantAdminAt($restaurant));

        $categories = $restaurant->menuCategories()
            ->when(! $canEditMenu, fn ($q) => $q->where('is_active', true))
            ->orderBy('position')
            ->with([
                'items' => function ($q) use ($canEditMenu): void {
                    if (! $canEditMenu) {
                        $q->where('is_available', true);
                    }
                    $q->orderBy('position');
                },
                'items.template.groups.options',
                'items.defaultSelections',
            ])
            ->get()
            ->when(! $canEditMenu, fn ($cats) => $cats->filter(fn ($c) => $c->items->isNotEmpty()))
            ->values()
            ->map(fn ($c) => MenuCategoryData::fromModel($c))
            ->all();

        return Inertia::render('Storefront/Home', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'categories' => $categories,
            'brand' => BrandColors::paletteFor(
                $restaurant->primary_color,
                $restaurant->secondary_color,
            ),
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
