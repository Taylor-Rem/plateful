<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveAdminRestaurant
{
    public function __construct(protected CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $restaurant = $request->route('restaurant');

        if (! $restaurant instanceof Restaurant) {
            throw new NotFoundHttpException;
        }

        $user = Auth::user();

        if (! $user || ! $user->canAccessRestaurant($restaurant)) {
            throw new AccessDeniedHttpException;
        }

        $this->currentTenant->set($restaurant);

        // Feeds the sidebar's restaurant switcher. Lazy so partial reloads
        // skip it; capped because a super admin can access every restaurant.
        Inertia::share('adminRestaurants', fn () => $user
            ->accessibleRestaurants()
            ->take(10)
            ->map(fn (Restaurant $r) => [
                'name' => $r->name,
                'subdomain' => $r->subdomain,
            ])
            ->values()
            ->all());

        return $next($request);
    }
}
