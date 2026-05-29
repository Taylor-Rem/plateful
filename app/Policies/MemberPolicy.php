<?php

namespace App\Policies;

use App\Models\User;

class MemberPolicy
{
    /**
     * An admin cannot remove themselves from a restaurant.
     *
     * Cross-tenant access is already enforced by the admin.restaurant.admin
     * route middleware, so the only authorization rule worth living in a
     * policy here is the self-protection one. Keeping it in the policy means
     * any new surface that removes a member picks it up automatically.
     */
    public function delete(User $user, User $member): bool
    {
        return $user->id !== $member->id;
    }
}
