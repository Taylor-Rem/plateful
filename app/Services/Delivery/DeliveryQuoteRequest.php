<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Models\Restaurant;

class DeliveryQuoteRequest
{
    /**
     * @param  array<string, mixed>  $dropoffAddress
     * @param  array<int, array{name: string, quantity: int, external_id: string|null}>  $items
     */
    public function __construct(
        public readonly Restaurant $restaurant,
        public readonly array $dropoffAddress,
        public readonly int $subtotalCents,
        public readonly int $tipCents,
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        public readonly ?Order $order = null,
        public readonly array $items = [],
    ) {}

    /**
     * The order's lines in the provider-neutral item shape, for providers
     * (DoorDash) that want the parcel's contents on the quote.
     *
     * @return array<int, array{name: string, quantity: int, external_id: string|null}>
     */
    public static function itemsFromOrder(Order $order): array
    {
        return $order->items
            ->map(fn ($line): array => [
                'name' => (string) $line->name,
                'quantity' => (int) $line->quantity,
                'external_id' => $line->menu_item_id !== null ? (string) $line->menu_item_id : null,
            ])
            ->values()
            ->all();
    }
}
