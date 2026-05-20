<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Data\OrderData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrdersController extends Controller
{
    public function index(Request $request, CurrentTenant $tenant): Response
    {
        $user = $request->user();
        $restaurant = $tenant->get();

        $paginator = Order::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->through(fn (Order $o) => OrderData::fromModel($o));

        return Inertia::render('Storefront/Account/Orders', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'orders' => $paginator,
        ]);
    }

    public function show(Request $request, CurrentTenant $tenant, string $number): Response
    {
        $user = $request->user();
        $restaurant = $tenant->get();

        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->where('number', $number)
            ->with('items')
            ->first();

        abort_if(! $order, 404);

        return Inertia::render('Storefront/OrderConfirmation', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'order' => OrderData::fromModel($order),
            'brand' => BrandColors::paletteFor(
                $restaurant->primary_color,
                $restaurant->secondary_color,
            ),
            'fromAccount' => true,
        ]);
    }
}
