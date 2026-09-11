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
 * (/api/v1/restaurants/{restaurant}/...), so the tenant comes from the route
 * rather than the Host header. Only live storefronts resolve — everything
 * else is a 404, never a preview — and once CurrentTenant is set every
 * tenant-scoped service downstream runs unchanged.
 *
 * Runs BEFORE SubstituteBindings (see the priority list in bootstrap/app.php)
 * and resolves the restaurant from the raw subdomain parameter: that way
 * every tenant-scoped binding on the route ({menuItem}, …) is resolved with
 * the tenant already set, so a URL for restaurant A can never reach
 * restaurant B's rows.
 */
class ResolveTenantFromRoute
{
    public function __construct(protected CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $parameter = $request->route('restaurant');

        $restaurant = $parameter instanceof Restaurant
            ? $parameter
            : Restaurant::query()->where('subdomain', (string) $parameter)->first();

        if (! $restaurant instanceof Restaurant || ! $restaurant->isLive()) {
            throw new NotFoundHttpException;
        }

        // Only the tenant is set here; SubstituteBindings still performs the
        // {restaurant} binding itself (its explicit binder expects the raw
        // subdomain, not a model).
        $this->currentTenant->set($restaurant);

        return $next($request);
    }
}
