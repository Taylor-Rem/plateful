<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum,api-key` on every operator route: turns the
 * authenticated principal into an ApiActor (refusing customer-only tokens
 * with a 403) so the rest of the pipeline never has to ask which guard
 * signed the request.
 */
class AuthenticateOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(ApiActor::class, ApiActor::fromRequest($request));

        return $next($request);
    }
}
