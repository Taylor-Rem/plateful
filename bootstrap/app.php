<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OptionalSanctumAuth;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireRestaurantAdmin;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\RequireTwoFactorEnrollment;
use App\Http\Middleware\ResolveAdminRestaurant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ResolveTenantFromRoute;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The public API is stateless bearer-token JSON on the primary host;
            // routes/api.php pins the domain and the /api/v1 prefix itself.
            Route::middleware('api')->group(base_path('routes/api.php'));
            Route::middleware('web')->group(base_path('routes/storefront.php'));
            Route::middleware('web')->group(base_path('routes/webhooks.php'));
            // super-admin.php registers before admin.php so /super/* can never
            // be captured by admin.php's {restaurant} wildcard prefix.
            Route::middleware('web')->group(base_path('routes/super-admin.php'));
            Route::middleware('web')->group(base_path('routes/admin.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->throttleApi();

        // The API tenant must be known before route-model bindings resolve, so
        // tenant-scoped models bound on /restaurants/{restaurant}/... routes
        // are looked up inside that tenant (see ResolveTenantFromRoute).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantFromRoute::class);

        $middleware->validateCsrfTokens(except: ['stripe/webhook', 'webhooks/uber', 'webhooks/doordash', 'webhooks/resend']);

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
            'two-factor.required' => RequireTwoFactorEnrollment::class,
            'tenant.route' => ResolveTenantFromRoute::class,
            'auth.optional' => OptionalSanctumAuth::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        // API clients get JSON errors whether or not they sent an Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
