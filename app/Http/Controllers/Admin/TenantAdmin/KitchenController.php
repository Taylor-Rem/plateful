<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\OrderData;
use App\Data\RestaurantData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class KitchenController extends Controller
{
    /**
     * Statuses surfaced on the kitchen board, in display order.
     */
    private const BOARD_STATUSES = [
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::Ready,
    ];

    public function index(Restaurant $restaurant): Response
    {
        $statuses = array_map(fn (OrderStatus $s) => $s->value, self::BOARD_STATUSES);

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
