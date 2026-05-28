<?php

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;

class MenuItemPolicy
{
    /**
     * Create a new menu item for the given restaurant.
     *
     * Authorization differs from update/delete only in that no item exists yet,
     * so the restaurant context must be passed explicitly. Controllers call
     * `$this->authorize('create', [MenuItem::class, $restaurant])`.
     */
    public function create(User $user, Restaurant $restaurant): bool
    {
        return $this->isAdminOf($user, $restaurant);
    }

    public function update(User $user, MenuItem $item): bool
    {
        return $this->isAdminOf($user, $item->restaurant_id);
    }

    public function delete(User $user, MenuItem $item): bool
    {
        return $this->isAdminOf($user, $item->restaurant_id);
    }

    protected function isAdminOf(User $user, Restaurant|int $restaurant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isRestaurantAdminAt($restaurant);
    }
}
