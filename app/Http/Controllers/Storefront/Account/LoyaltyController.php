<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Data\OrderData;
use App\Data\RestaurantData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoints;
use App\Models\Order;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoyaltyController extends Controller
{
    public function show(Request $request, CurrentTenant $tenant): Response
    {
        $user = $request->user();
        $restaurant = $tenant->get();

        $balance = (int) (LoyaltyPoints::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->value('points') ?? 0);

        $recent = Order::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->where('status', OrderStatus::Completed->value)
            ->where('awarded_loyalty_points', '>', 0)
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Order $o) => OrderData::fromModel($o))
            ->all();

        return Inertia::render('Storefront/Account/Loyalty', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'balance' => $balance,
            'pointsPerDollar' => (int) config('platform.loyalty.points_per_dollar', 1),
            'recentOrders' => $recent,
        ]);
    }
}
