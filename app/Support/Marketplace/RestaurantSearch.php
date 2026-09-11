<?php

namespace App\Support\Marketplace;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Discovery query behind GET /api/v1/restaurants: live, listed restaurants
 * filtered by cuisine, text, fulfilment, open-now and distance, ordered by
 * distance when the diner shared a location and by name otherwise.
 *
 * Distance is a SQL bounding box (portable, indexable) followed by an exact
 * Haversine in PHP; open-now is PHP too since it depends on hours rows and
 * the restaurant's timezone. The listed set is small enough that pulling it
 * and paginating in memory is the honest choice — PostGIS only if scale
 * ever demands it (plateful_app_plan.md).
 */
class RestaurantSearch
{
    protected const EARTH_RADIUS_KM = 6371.0;

    protected const KM_PER_DEGREE_LAT = 111.32;

    public function __construct(
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $radiusKm = null,
        public readonly bool $openNow = false,
        public readonly ?string $cuisine = null,
        public readonly ?string $query = null,
        public readonly ?string $fulfilment = null,
    ) {}

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function radiusKm(): float
    {
        $max = (float) config('platform.marketplace.max_radius_km', 100);
        $radius = $this->radiusKm ?? (float) config('platform.marketplace.default_radius_km', 25);

        return max(0.1, min($radius, $max));
    }

    /**
     * Matching restaurants with `hours` loaded and, when a location was
     * given, `distance_km` set on each model.
     *
     * @return Collection<int, Restaurant>
     */
    public function get(): Collection
    {
        $query = Restaurant::query()
            ->marketplaceListed()
            ->with('hours');

        if ($this->cuisine !== null) {
            $query->whereJsonContains('cuisine_tags', $this->cuisine);
        }

        if ($this->query !== null && trim($this->query) !== '') {
            $needle = '%'.mb_strtolower(trim($this->query)).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(description, \'\')) like ?', [$needle])
                    ->orWhereRaw('lower(city) like ?', [$needle]);
            });
        }

        if ($this->fulfilment === 'delivery') {
            $query->where('delivery_enabled', true);
        }

        if ($this->hasLocation()) {
            $this->applyBoundingBox($query);
        }

        $restaurants = $query->get();

        if ($this->hasLocation()) {
            $radius = $this->radiusKm();

            $restaurants = $restaurants
                ->each(fn (Restaurant $r) => $r->distance_km = $this->distanceTo($r))
                ->filter(fn (Restaurant $r) => $r->distance_km <= $radius)
                ->sortBy(fn (Restaurant $r) => [$r->distance_km, $r->name]);
        } else {
            $restaurants = $restaurants->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
        }

        if ($this->openNow) {
            $restaurants = $restaurants->filter(fn (Restaurant $r) => $r->isOpenAt());
        }

        return $restaurants->values();
    }

    protected function applyBoundingBox(Builder $query): void
    {
        $radius = $this->radiusKm();
        $latDelta = $radius / self::KM_PER_DEGREE_LAT;
        $lngDelta = $radius / (self::KM_PER_DEGREE_LAT * max(cos(deg2rad($this->latitude)), 0.01));

        $query->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$this->latitude - $latDelta, $this->latitude + $latDelta])
            ->whereBetween('longitude', [$this->longitude - $lngDelta, $this->longitude + $lngDelta]);
    }

    /**
     * Great-circle distance in km from the search origin, rounded to 0.1.
     */
    public function distanceTo(Restaurant $restaurant): float
    {
        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad((float) $restaurant->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $restaurant->longitude - $this->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return round(self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}
