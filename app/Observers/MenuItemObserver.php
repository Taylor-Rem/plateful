<?php

namespace App\Observers;

use App\Models\MenuItem;
use App\Services\RestaurantImageService;

class MenuItemObserver
{
    public function __construct(protected RestaurantImageService $images) {}

    public function updated(MenuItem $item): void
    {
        if ($item->wasChanged('image_path')) {
            $previous = $item->getOriginal('image_path');
            if ($previous && $previous !== $item->image_path) {
                $this->images->deleteVariants($previous);
            }
        }
    }

    public function deleted(MenuItem $item): void
    {
        $this->images->deleteDirectoryForMenuItem($item);
    }
}
