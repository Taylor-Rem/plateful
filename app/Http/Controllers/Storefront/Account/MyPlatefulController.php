<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Models\RestaurantCustomer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyPlatefulController extends Controller
{
    /**
     * Show every restaurant the authenticated user has a relationship with
     * on the Plateful platform — signed up at or ordered from.
     *
     * Cross-tenant by design: this page intentionally aggregates data across
     * all restaurants for the current Plateful account.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        $pivots = RestaurantCustomer::query()
            ->with('restaurant:id,name,subdomain,custom_domain,logo_path')
            ->where('user_id', $user->id)
            ->orderByRaw('last_ordered_at IS NULL, last_ordered_at DESC')
            ->orderBy('id')
            ->get();

        $restaurants = $pivots
            ->filter(fn (RestaurantCustomer $p) => $p->restaurant !== null)
            ->map(fn (RestaurantCustomer $p) => [
                'id' => $p->restaurant->id,
                'name' => $p->restaurant->name,
                'subdomain' => $p->restaurant->subdomain,
                'logoUrl' => $p->restaurant->logoThumbUrl(),
                'publicUrl' => $p->restaurant->publicUrl('http'),
                'totalOrders' => (int) $p->total_orders,
                'totalSpentCents' => (int) $p->total_spent_cents,
                'firstOrderedAt' => optional($p->first_ordered_at)->toIso8601String(),
                'lastOrderedAt' => optional($p->last_ordered_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Storefront/Account/MyPlateful', [
            'restaurants' => $restaurants,
        ]);
    }
}
