<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RequireRestaurantAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $restaurant = $request->route('restaurant');

        if (! $restaurant instanceof Restaurant) {
            throw new NotFoundHttpException;
        }

        $user = Auth::user();

        if (! $user || ! $user->isRestaurantAdminAt($restaurant)) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
