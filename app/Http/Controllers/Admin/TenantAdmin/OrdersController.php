<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class OrdersController extends Controller
{
    public function index(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Orders', [
            'restaurant' => RestaurantData::fromModel($restaurant),
        ]);
    }
}
