<?php

namespace App\Services\Delivery;

use App\Contracts\DeliveryProvider;
use App\Enums\DeliveryMode;
use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\Restaurant;

class SelfDeliveryProvider implements DeliveryProvider
{
    public function name(): DeliveryProviderName
    {
        return DeliveryProviderName::Self;
    }

    public function supports(Restaurant $restaurant): bool
    {
        return (bool) $restaurant->delivery_enabled
            && $restaurant->delivery_mode === DeliveryMode::SelfDelivery;
    }

    public function quote(DeliveryQuoteRequest $request): DeliveryQuote
    {
        return new DeliveryQuote(
            provider: $this->name(),
            feeCents: (int) $request->restaurant->delivery_fee_cents,
            etaMinutes: 40,
        );
    }

    public function create(Order $order, DeliveryQuote $quote): DeliveryAssignment
    {
        return DeliveryAssignment::create([
            'order_id' => $order->id,
            'provider' => $this->name(),
            'status' => DeliveryStatus::Pending,
            'quote_fee_cents' => $quote->feeCents,
        ]);
    }

    public function status(DeliveryAssignment $assignment): DeliveryAssignment
    {
        return $assignment->fresh() ?? $assignment;
    }

    public function cancel(DeliveryAssignment $assignment): void
    {
        $assignment->update(['status' => DeliveryStatus::Cancelled]);
    }
}
