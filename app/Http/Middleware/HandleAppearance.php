<?php

namespace App\Http\Middleware;

use App\Support\AppearanceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = AppearanceContext::forHost($request->getHost());
        $cookie = $request->cookie('appearance') ?? 'system';

        // Tenant storefronts are always light; the cookie has no effect there.
        $appearance = $context === AppearanceContext::TENANT ? 'light' : $cookie;

        View::share('appearance', $appearance);
        View::share('appearanceContext', $context);

        return $next($request);
    }
}
