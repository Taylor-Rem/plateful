<?php

namespace App\Http\Middleware;

use App\Enums\ApiKeyScope;
use App\Support\Api\ApiActor;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `operator.scope:orders:write` — the actor must hold the scope, at the
 * route's restaurant when there is one. Keys carry scopes; users derive
 * them from their pivot role (see ApiKeyScope::forRole).
 *
 * Runs BEFORE SubstituteBindings (priority list in bootstrap/app.php), so a
 * credential without the scope is refused before any {order} / {menuItem}
 * lookup can tell it whether the id exists. The restaurant therefore comes
 * from CurrentTenant, which ResolveOperatorRestaurant has already set.
 */
class RequireApiScope
{
    public function __construct(protected CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $required = ApiKeyScope::from($scope);

        if (! ApiActor::fromRequest($request)->hasScope($required, $this->currentTenant->get())) {
            throw new AccessDeniedHttpException("This credential lacks the {$required->value} scope.");
        }

        return $next($request);
    }
}
