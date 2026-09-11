<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest-or-customer API routes (carts, quotes, checkout). Makes Sanctum the
 * guard behind `$request->user()` without rejecting guests, so CartManager
 * and OrderPlacement see the bearer user when there is one and null when
 * there isn't — exactly as they see the web session.
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
