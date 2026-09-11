<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\Places\RestaurantGeocoder;
use Illuminate\Console\Command;

class GeocodeRestaurantsCommand extends Command
{
    protected $signature = 'restaurants:geocode
        {--force : Re-geocode restaurants that already have coordinates}';

    protected $description = 'Backfill restaurant coordinates for the app\'s near-me search';

    public function handle(RestaurantGeocoder $geocoder): int
    {
        if (! $geocoder->configured()) {
            $this->error('GOOGLE_GEOCODING_API_KEY is not set; nothing to do.');

            return self::FAILURE;
        }

        $restaurants = Restaurant::query()
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('latitude'))
            ->orderBy('id')
            ->get();

        $placed = 0;

        foreach ($restaurants as $restaurant) {
            if ($geocoder->geocode($restaurant)) {
                $placed++;
                $this->line("  placed {$restaurant->name} ({$restaurant->subdomain}) at {$restaurant->latitude}, {$restaurant->longitude}");
            } else {
                $this->warn("  could not place {$restaurant->name} ({$restaurant->subdomain})");
            }
        }

        $this->info("Geocoded {$placed} of {$restaurants->count()} restaurants.");

        return self::SUCCESS;
    }
}
