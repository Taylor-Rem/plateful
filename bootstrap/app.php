<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireRestaurantAdmin;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\ResolveAdminRestaurant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/storefront.php'));
            Route::middleware('web')->group(base_path('routes/super-admin.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: ['stripe/webhook', 'webhooks/uber']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'admin' => RequireAdmin::class,
            'super' => RequireSuperAdmin::class,
            'admin.restaurant' => ResolveAdminRestaurant::class,
            'admin.restaurant.admin' => RequireRestaurantAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })->create();
