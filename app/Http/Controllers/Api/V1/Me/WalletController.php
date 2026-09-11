<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Data\RestaurantSummaryData;
use App\Data\WalletEntryData;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoints;
use App\Models\RestaurantCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Points and standing at every restaurant the customer has a relationship
 * with. Per-restaurant ownership is unchanged (§10): this aggregates, it
 * never pools. Read-only until §10 decides redemption.
 */
class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $points = LoyaltyPoints::withoutTenantScope()
            ->where('user_id', $user->id)
            ->pluck('points', 'restaurant_id');

        $pivots = RestaurantCustomer::query()
            ->with('restaurant.hours')
            ->where('user_id', $user->id)
            ->orderByRaw('last_ordered_at IS NULL, last_ordered_at DESC')
            ->orderBy('id')
            ->get()
            ->filter(fn (RestaurantCustomer $p) => $p->restaurant !== null);

        $rate = (int) config('platform.loyalty.points_per_dollar', 1);

        return response()->json([
            'data' => $pivots->map(fn (RestaurantCustomer $p) => new WalletEntryData(
                restaurant: RestaurantSummaryData::fromModel($p->restaurant),
                loyaltyPoints: (int) ($points[$p->restaurant_id] ?? 0),
                pointsPerDollar: $rate,
                totalOrders: (int) $p->total_orders,
                totalSpentCents: (int) $p->total_spent_cents,
                lastOrderedAt: $p->last_ordered_at?->toIso8601String(),
            ))->values()->all(),
        ]);
    }
}
