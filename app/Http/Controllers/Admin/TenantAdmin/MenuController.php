<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\MenuCategoryData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function index(Restaurant $restaurant): Response
    {
        $categories = $restaurant->menuCategories()
            ->orderBy('position')
            ->with(['items' => fn ($q) => $q->orderBy('position')])
            ->get()
            ->map(fn ($c) => MenuCategoryData::fromModel($c))
            ->all();

        return Inertia::render('Admin/TenantAdmin/Menu', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'categories' => $categories,
        ]);
    }
}
