<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Services\Places\RestaurantGeocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-place a restaurant on the map after its address is saved. Dispatched
 * by the address writers (settings, onboarding basics, super-admin create)
 * rather than a model observer, so a factory-created restaurant in a test
 * never reaches out to Google.
 */
class GeocodeRestaurant implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $restaurantId) {}

    public function handle(RestaurantGeocoder $geocoder): void
    {
        $restaurant = Restaurant::query()->find($this->restaurantId);

        if ($restaurant !== null) {
            $geocoder->geocode($restaurant);
        }
    }
}
