<?php

namespace App\Http\Controllers\Storefront;

use App\Data\OrderData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function show(Request $request, CurrentTenant $tenant, string $number): Response
    {
        $restaurant = $tenant->get();

        $order = Order::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('number', $number)
            ->with('items')
            ->first();

        abort_if(! $order, 404);

        $cookieToken = $request->cookie(CheckoutController::RECENT_ORDER_COOKIE);

        abort_if(! Gate::allows('view', [$order, $cookieToken]), 404);

        return Inertia::render('Storefront/OrderConfirmation', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'order' => OrderData::fromModel($order),
            'brand' => BrandColors::paletteFor(
                $restaurant->primary_color,
                $restaurant->secondary_color,
            ),
        ]);
    }
}
