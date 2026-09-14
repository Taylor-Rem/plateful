<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Support\Api\ApiActor;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operator counterpart of ResolveTenantFromRoute. The restaurant in
 * /api/v1/operator/restaurants/{restaurant}/... resolves for the actor that
 * signed the request — their own restaurants, or every restaurant for a
 * platform actor — and, unlike the customer API, need not be live: an owner
 * finishing onboarding can already work their menu here. Anything the actor
 * cannot reach is a 404, never a 403, so the URL space reveals nothing.
 *
 * Runs BEFORE SubstituteBindings (priority list in bootstrap/app.php) so the
 * tenant is set before {order}, {menuItem}, … are bound inside it.
 */
class ResolveOperatorRestaurant
{
    public function __construct(protected CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = ApiActor::fromRequest($request);
        $parameter = $request->route('restaurant');

        $restaurant = $parameter instanceof Restaurant
            ? $parameter
            : Restaurant::query()->where('subdomain', (string) $parameter)->first();

        if (! $restaurant instanceof Restaurant || ! $actor->canAccessRestaurant($restaurant)) {
            throw new NotFoundHttpException;
        }

        $this->currentTenant->set($restaurant);

        return $next($request);
    }
}
