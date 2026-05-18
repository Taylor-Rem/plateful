<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantsController extends Controller
{
    public function index(): Response
    {
        $restaurants = Restaurant::query()
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => RestaurantData::fromModel($r))
            ->all();

        return Inertia::render('Admin/SuperAdmin/Restaurants', [
            'restaurants' => $restaurants,
        ]);
    }
}
