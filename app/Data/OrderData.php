<?php

namespace App\Data;

use App\Models\Order;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OrderData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public string $status,
        public string $type,
        public string $customerName,
        public string $customerEmail,
        public ?string $customerPhone,
        /** @var array<string, mixed>|null */
        public ?array $deliveryAddress,
        public int $subtotalCents,
        public int $taxCents,
        public int $tipCents,
        public int $deliveryFeeCents,
        public int $totalCents,
        public int $awardedLoyaltyPoints,
        public ?string $notes,
        public ?string $placedAt,
        #[DataCollectionOf(OrderItemData::class)]
        /** @var array<int, OrderItemData> */
        public array $items,
    ) {}

    public static function fromModel(Order $order): self
    {
        $order->loadMissing('items');

        return new self(
            id: $order->id,
            number: $order->number,
            status: $order->status->value,
            type: $order->type->value,
            customerName: (string) $order->customer_name,
            customerEmail: (string) $order->customer_email,
            customerPhone: $order->customer_phone,
            deliveryAddress: $order->delivery_address,
            subtotalCents: (int) $order->subtotal_cents,
            taxCents: (int) $order->tax_cents,
            tipCents: (int) $order->tip_cents,
            deliveryFeeCents: (int) $order->delivery_fee_cents,
            totalCents: (int) $order->total_cents,
            awardedLoyaltyPoints: (int) ($order->awarded_loyalty_points ?? 0),
            notes: $order->notes,
            placedAt: $order->placed_at?->toIso8601String(),
            items: $order->items
                ->map(fn ($i) => OrderItemData::fromModel($i))
                ->values()
                ->all(),
        );
    }
}
