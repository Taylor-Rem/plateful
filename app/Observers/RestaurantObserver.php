<?php

namespace App\Observers;

use App\Models\Restaurant;
use App\Services\RestaurantImageService;

class RestaurantObserver
{
    public function __construct(protected RestaurantImageService $images) {}

    public function updated(Restaurant $restaurant): void
    {
        if ($restaurant->wasChanged('logo_path')) {
            $previous = $restaurant->getOriginal('logo_path');
            if ($previous && $previous !== $restaurant->logo_path) {
                $this->images->deleteVariants($previous);
            }
        }
    }

    public function deleted(Restaurant $restaurant): void
    {
        $this->images->deleteDirectoryForRestaurant($restaurant);
    }
}
