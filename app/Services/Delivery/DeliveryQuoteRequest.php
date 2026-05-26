<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Models\Restaurant;

class DeliveryQuoteRequest
{
    /**
     * @param  array<string, mixed>  $dropoffAddress
     */
    public function __construct(
        public readonly Restaurant $restaurant,
        public readonly array $dropoffAddress,
        public readonly int $subtotalCents,
        public readonly int $tipCents,
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        public readonly ?Order $order = null,
    ) {}
}
