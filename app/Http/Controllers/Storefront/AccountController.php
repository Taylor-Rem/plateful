<?php

namespace App\Http\Controllers\Storefront;

use App\Data\AccountSummaryData;
use App\Data\AddressData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoints;
use App\Models\Order;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request, CurrentTenant $tenant): Response
    {
        $user = $request->user();
        $restaurant = $tenant->get();

        $orderCount = Order::query()->where('user_id', $user->id)->count();
        $addressCount = $user->addresses()->count();
        $points = (int) (LoyaltyPoints::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->value('points') ?? 0);

        $default = $user->addresses()->where('is_default', true)->first()
            ?? $user->addresses()->orderBy('id')->first();

        return Inertia::render('Storefront/Account/Home', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'summary' => new AccountSummaryData(
                userName: (string) $user->name,
                userEmail: (string) $user->email,
                userPhone: $user->phone,
                orderCount: $orderCount,
                addressCount: $addressCount,
                loyaltyPoints: $points,
                defaultAddress: $default ? AddressData::fromModel($default) : null,
            ),
        ]);
    }
}
