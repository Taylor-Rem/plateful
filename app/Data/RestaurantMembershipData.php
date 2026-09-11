<?php

namespace App\Data;

use App\Models\LoyaltyPoints;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The signed-in customer's relationship with one restaurant: favourite,
 * marketing consent, points, and order totals. Everything the app shows on
 * a restaurant screen that depends on who is looking.
 */
#[TypeScript]
class RestaurantMembershipData extends Data
{
    public function __construct(
        public bool $isFavorite,
        public bool $marketingOptedIn,
        public int $loyaltyPoints,
        public int $totalOrders,
        public int $totalSpentCents,
        public ?string $lastOrderedAt,
    ) {}

    public static function for(User $user, Restaurant $restaurant): self
    {
        $pivot = RestaurantCustomer::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->first();

        $points = (int) (LoyaltyPoints::withoutTenantScope()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->value('points') ?? 0);

        return new self(
            isFavorite: $user->favoriteRestaurants()->whereKey($restaurant->id)->exists(),
            marketingOptedIn: $pivot?->isEmailOptedIn() ?? false,
            loyaltyPoints: $points,
            totalOrders: (int) ($pivot?->total_orders ?? 0),
            totalSpentCents: (int) ($pivot?->total_spent_cents ?? 0),
            lastOrderedAt: $pivot?->last_ordered_at?->toIso8601String(),
        );
    }
}
