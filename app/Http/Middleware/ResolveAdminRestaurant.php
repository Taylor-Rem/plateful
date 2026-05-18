<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return $next($request);
    }
}
