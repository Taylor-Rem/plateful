<?php

namespace App\Http\Controllers\Storefront;

use App\Data\RestaurantData;
use App\Data\RestaurantPhotoData;
use App\Http\Controllers\Controller;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(CurrentTenant $tenant): Response
    {
        $restaurant = $tenant->get();

        $photos = $restaurant->photos()
            ->get()
            ->map(fn ($p) => RestaurantPhotoData::fromModel($p))
            ->all();

        return Inertia::render('Storefront/Home', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'photos' => $photos,
            'brand' => BrandColors::paletteFor(
                $restaurant->primary_color,
                $restaurant->secondary_color,
            ),
        ]);
    }
}
