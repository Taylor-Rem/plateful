<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RequirePlatformHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() !== config('platform.primary_domain')) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
