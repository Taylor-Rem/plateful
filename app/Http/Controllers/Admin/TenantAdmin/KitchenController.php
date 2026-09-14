<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\OrderData;
use App\Data\RestaurantData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Support\Operator\OperatorOrders;
use Inertia\Inertia;
use Inertia\Response;

class KitchenController extends Controller
{
    public function index(Restaurant $restaurant): Response
    {
        $statuses = array_map(fn (OrderStatus $s) => $s->value, OperatorOrders::BOARD_STATUSES);

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', $statuses)
            ->with('items')
            ->orderBy('placed_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Order $o) => OrderData::fromModel($o))
            ->all();

        return Inertia::render('Admin/TenantAdmin/Kitchen', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'orders' => $orders,
        ]);
    }
}
