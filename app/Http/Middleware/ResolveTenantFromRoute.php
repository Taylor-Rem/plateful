<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * API counterpart of ResolveTenant. The public API runs on the primary host
 * and addresses restaurants by subdomain in the path
 * (/api/v1/restaurants/{restaurant}/...), so the tenant comes from the bound
 * route model rather than the Host header. Only live storefronts resolve —
 * everything else is a 404, never a preview — and once CurrentTenant is set
 * every tenant-scoped service downstream runs unchanged.
 */
class ResolveTenantFromRoute
{
    public function __construct(protected CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $restaurant = $request->route('restaurant');

        if (! $restaurant instanceof Restaurant || ! $restaurant->isLive()) {
            throw new NotFoundHttpException;
        }

        $this->currentTenant->set($restaurant);

        return $next($request);
    }
}
