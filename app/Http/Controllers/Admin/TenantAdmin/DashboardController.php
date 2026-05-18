<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Dashboard', [
            'restaurant' => RestaurantData::fromModel($restaurant),
        ]);
    }
}
