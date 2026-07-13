<?php

namespace App\Http\Middleware;

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveTenant
{
    public function __construct(protected CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $primary = config('platform.primary_domain');
        $adminHost = config('platform.admin_subdomain').'.'.$primary;

        if ($host === $adminHost || $host === $primary) {
            return $next($request);
        }

        $restaurant = Restaurant::query()->where('custom_domain', $host)->first();

        if (! $restaurant && str_ends_with($host, '.'.$primary)) {
            $subdomain = substr($host, 0, -strlen('.'.$primary));
            $restaurant = Restaurant::query()->where('subdomain', $subdomain)->first();
        }

        if (! $restaurant) {
            throw new NotFoundHttpException;
        }

        // Suspended and approved-but-not-yet-live restaurants also serve the
        // Unavailable page. The owner toggle `is_active = false` works too.
        $isStorefrontLive = $restaurant->is_active
            && $restaurant->status === RestaurantStatus::Active;

        if (! $isStorefrontLive) {
            if (! $this->canPreview($request, $restaurant)) {
                return Inertia::render('Storefront/Unavailable', [
                    'restaurantName' => $restaurant->name,
                ])
                    ->toResponse($request)
                    ->setStatusCode(503);
            }

            Inertia::share('storefrontPreview', true);
        }

        $this->currentTenant->set($restaurant);

        View::share('brandPalette', BrandColors::paletteFor(
            $restaurant->primary_color,
            $restaurant->secondary_color,
        ));

        View::share('tenantSeo', [
            'title' => $restaurant->seoTitle(),
            'description' => $restaurant->seoDescription(),
            'ogImage' => $restaurant->ogImageUrl(),
            'url' => $restaurant->publicUrl($request->getScheme() ?: 'https'),
            'siteName' => $restaurant->name,
        ]);

        return $next($request);
    }

    /**
     * Admins of this restaurant (and super admins) may browse a not-yet-live
     * storefront so they can see their site take shape during onboarding.
     * The token-exchange route must also pass: it's how the admin's login
     * reaches this host in the first place (sessions are host-scoped).
     */
    protected function canPreview(Request $request, Restaurant $restaurant): bool
    {
        if ($request->routeIs('storefront.preview.enter')) {
            return true;
        }

        $user = $request->user();

        return $user !== null
            && ($user->isSuperAdmin() || $user->isRestaurantAdminAt($restaurant));
    }
}
