<?php

namespace App\Data;

use App\Models\FeeDistribution;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the earnings ledger: a slice of one order's retained fee
 * attributed to one person in one role.
 */
#[TypeScript]
class FeeDistributionData extends Data
{
    public function __construct(
        public int $id,
        public int $orderId,
        public ?string $orderNumber,
        public bool $orderRefunded,
        public int $restaurantId,
        public ?string $restaurantName,
        public ?string $restaurantSubdomain,
        public ?int $userId,
        public ?string $userName,
        public ?string $userEmail,
        public string $role,
        public float $percent,
        public int $amountCents,
        public string $earnedAt,
    ) {}

    public static function fromModel(FeeDistribution $row): self
    {
        $row->loadMissing(['order:id,number,refunded_at', 'restaurant:id,name,subdomain', 'user:id,name,email']);

        return new self(
            id: $row->id,
            orderId: $row->order_id,
            orderNumber: $row->order?->number,
            orderRefunded: $row->order?->refunded_at !== null,
            restaurantId: $row->restaurant_id,
            restaurantName: $row->restaurant?->name,
            restaurantSubdomain: $row->restaurant?->subdomain,
            userId: $row->user_id,
            userName: $row->user?->name,
            userEmail: $row->user?->email,
            role: $row->role->value,
            percent: (float) $row->percent,
            amountCents: (int) $row->amount_cents,
            earnedAt: $row->earned_at->toIso8601String(),
        );
    }
}
