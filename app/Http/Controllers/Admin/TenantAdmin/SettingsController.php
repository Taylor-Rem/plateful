<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Settings', [
            'restaurant' => RestaurantData::fromModel($restaurant),
        ]);
    }
}
