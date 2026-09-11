<?php

namespace App\Data;

use App\Models\Order;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the app's cross-restaurant order history.
 */
#[TypeScript]
class OrderHistoryItemData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public string $status,
        public string $type,
        public int $totalCents,
        public int $itemCount,
        public ?string $placedAt,
        public ?string $deliveryStatus,
        public string $restaurantName,
        public string $restaurantSubdomain,
        public ?string $restaurantLogoThumbUrl,
    ) {}

    public static function fromModel(Order $order): self
    {
        $order->loadMissing(['restaurant', 'items', 'deliveryAssignment']);

        return new self(
            id: $order->id,
            number: $order->number,
            status: $order->status->value,
            type: $order->type->value,
            totalCents: (int) $order->total_cents,
            itemCount: (int) $order->items->sum('quantity'),
            placedAt: $order->placed_at?->toIso8601String(),
            deliveryStatus: $order->deliveryAssignment?->status?->value,
            restaurantName: (string) $order->restaurant?->name,
            restaurantSubdomain: (string) $order->restaurant?->subdomain,
            restaurantLogoThumbUrl: $order->restaurant?->logoThumbUrl(),
        );
    }
}
