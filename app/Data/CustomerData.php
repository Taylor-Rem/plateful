<?php

namespace App\Data;

use App\Models\RestaurantCustomer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CustomerData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public int $totalOrders,
        public int $totalSpentCents,
        public ?string $firstOrderedAt,
        public ?string $lastOrderedAt,
        public int $loyaltyPoints,
        public bool $marketingOptedIn,
        public ?string $marketingOptedInAt,
    ) {}

    /**
     * Expects the customers-page query shape: a RestaurantCustomer row with
     * its user loaded and a `loyalty_points_balance` subselect.
     */
    public static function fromModel(RestaurantCustomer $pivot): self
    {
        return new self(
            id: $pivot->id,
            name: (string) $pivot->user->name,
            email: (string) $pivot->user->email,
            phone: $pivot->user->phone,
            totalOrders: (int) $pivot->total_orders,
            totalSpentCents: (int) $pivot->total_spent_cents,
            firstOrderedAt: $pivot->first_ordered_at?->toIso8601String(),
            lastOrderedAt: $pivot->last_ordered_at?->toIso8601String(),
            loyaltyPoints: (int) ($pivot->loyalty_points_balance ?? 0),
            marketingOptedIn: $pivot->isEmailOptedIn(),
            marketingOptedInAt: $pivot->isEmailOptedIn()
                ? $pivot->marketing_email_opted_in_at?->toIso8601String()
                : null,
        );
    }
}
