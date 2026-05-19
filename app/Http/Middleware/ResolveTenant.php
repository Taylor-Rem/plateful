<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
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

        $this->currentTenant->set($restaurant);

        View::share('brandPalette', BrandColors::paletteFor(
            $restaurant->primary_color,
            $restaurant->secondary_color,
        ));

        return $next($request);
    }
}
