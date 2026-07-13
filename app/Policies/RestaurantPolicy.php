<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    /**
     * Edit site-content fields (hero, about, gallery, etc.) on a restaurant's
     * public storefront. Granted to super admins and restaurant Admins.
     */
    public function updateSite(User $user, Restaurant $restaurant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isRestaurantAdminAt($restaurant);
    }

    /**
     * Manage the restaurant's Stripe Connect account (onboarding, dashboard
     * link). Granted to super admins and restaurant Admins.
     */
    public function manageStripe(User $user, Restaurant $restaurant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isRestaurantAdminAt($restaurant);
    }

    /**
     * Connect, disconnect, or reconnect the restaurant's POS integration.
     * Granted to super admins and restaurant Admins.
     */
    public function managePos(User $user, Restaurant $restaurant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isRestaurantAdminAt($restaurant);
    }
}
