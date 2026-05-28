<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Whether the actor can view a storefront order detail page.
     *
     * Two paths grant access:
     *   - The authenticated user owns the order (`order.user_id` matches).
     *   - The request carries the order's confirmation_token cookie set
     *     during checkout, so a guest can return to their own confirmation
     *     page without an account.
     *
     * Both paths need to stay aligned across every future storefront surface
     * that exposes order details (receipt emails, customer history, etc.),
     * so the rule lives here rather than inline in any controller.
     */
    public function view(?User $user, Order $order, ?string $cookieToken = null): bool
    {
        if ($user && $order->user_id === $user->id) {
            return true;
        }

        if ($order->confirmation_token && $cookieToken === $order->confirmation_token) {
            return true;
        }

        return false;
    }
}
