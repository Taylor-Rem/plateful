<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    /**
     * Manage a restaurant's owner-level surfaces: storefront site content,
     * Stripe Connect, and POS integrations. Granted to super admins and
     * restaurant Admins.
     *
     * On the admin host, authorization is the route middleware's job
     * (admin.restaurant.admin); this policy exists for the places middleware
     * can't reach — storefront-host admin actions and POS OAuth callbacks,
     * where the restaurant arrives via the OAuth state rather than the URL.
     */
    public function manage(User $user, Restaurant $restaurant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isRestaurantAdminAt($restaurant);
    }
}
