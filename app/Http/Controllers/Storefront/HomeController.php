<?php

namespace App\Http\Controllers\Storefront;

use App\Data\MenuCategoryData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentTenant;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(CurrentTenant $tenant): Response
    {
        $restaurant = $tenant->get();

        $categories = $restaurant->menuCategories()
            ->where('is_active', true)
            ->orderBy('position')
            ->with(['items' => function ($q): void {
                $q->orderBy('position');
            }, 'items.modifiers' => function ($q): void {
                $q->orderBy('position');
            }])
            ->get()
            ->map(fn ($c) => MenuCategoryData::fromModel($c))
            ->all();

        return Inertia::render('Storefront/Home', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'categories' => $categories,
        ]);
    }
}
