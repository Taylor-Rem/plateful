<?php

namespace App\Data;

use App\Models\Order;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A row in the dashboard's recent-orders list. Deliberately NOT OrderData,
 * which eager-loads every line item nobody renders there.
 */
#[TypeScript]
class OrderSummaryData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public string $status,
        public string $type,
        public string $customerName,
        public int $totalCents,
        public ?string $placedAt,
    ) {}

    public static function fromModel(Order $order): self
    {
        return new self(
            id: $order->id,
            number: $order->number,
            status: $order->status->value,
            type: $order->type->value,
            customerName: (string) $order->customer_name,
            totalCents: (int) $order->total_cents,
            placedAt: $order->placed_at?->toIso8601String(),
        );
    }
}
