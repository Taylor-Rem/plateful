<?php

namespace App\Http\Controllers\Storefront;

use App\Data\MenuItemData;
use App\Data\RestaurantData;
use App\Data\RestaurantPhotoData;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(CurrentTenant $tenant): Response
    {
        $restaurant = $tenant->get();

        $photos = $restaurant->photos()
            ->get()
            ->map(fn ($p) => RestaurantPhotoData::fromModel($p))
            ->all();

        $featuredItems = MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_featured', true)
            ->where('is_available', true)
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->with(['template.groups.options', 'defaultSelections'])
            ->orderBy('position')
            ->limit(6)
            ->get()
            ->map(fn ($i) => MenuItemData::fromModel($i))
            ->all();

        return Inertia::render('Storefront/Home', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'photos' => $photos,
            'featuredItems' => $featuredItems,
            'brand' => BrandColors::paletteFor(
                $restaurant->primary_color,
                $restaurant->secondary_color,
            ),
        ]);
    }
}
