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

        // Only the admin console supports dark mode. Tenant storefronts and
        // the apex marketing pages are always light; the cookie has no
        // effect there.
        $appearance = $context === AppearanceContext::ADMIN ? $cookie : 'light';

        View::share('appearance', $appearance);
        View::share('appearanceContext', $context);

        return $next($request);
    }
}
