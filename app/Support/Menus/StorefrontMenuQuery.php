<?php

namespace App\Support\Menus;

use App\Data\MenuCategoryData;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;

/**
 * The customer-facing menu tree — active categories, available items, with
 * every template, group, option, ingredient and default selection loaded —
 * shared by the storefront Menu page, the app's menu endpoint and (later)
 * the web marketplace. Editors get the hidden rows too.
 */
class StorefrontMenuQuery
{
    /**
     * @return Collection<int, MenuCategory>
     */
    public function categories(Restaurant $restaurant, bool $includeHidden = false): Collection
    {
        return $restaurant->menuCategories()
            ->when(! $includeHidden, fn ($q) => $q->where('is_active', true))
            ->orderBy('position')
            ->with([
                'items' => function ($q) use ($includeHidden): void {
                    if (! $includeHidden) {
                        $q->where('is_available', true);
                    }
                    $q->orderBy('position');
                },
                'items.templates.groups.options',
                'items.ownGroups.options',
                'items.ingredients',
                'items.defaultSelections',
            ])
            ->get()
            ->when(! $includeHidden, fn ($cats) => $cats->filter(fn ($c) => $c->items->isNotEmpty()))
            ->values();
    }

    /**
     * @return array<int, MenuCategoryData>
     */
    public function categoryData(Restaurant $restaurant, bool $includeHidden = false): array
    {
        return $this->categories($restaurant, $includeHidden)
            ->map(fn ($c) => MenuCategoryData::fromModel($c))
            ->all();
    }
}
