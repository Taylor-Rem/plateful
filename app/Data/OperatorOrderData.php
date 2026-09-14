<?php

namespace App\Data;

use App\Models\Order;
use App\Models\OrderEvent;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An order as an operator sees it: the customer-facing OrderData plus the
 * payment state and the status timeline.
 */
#[TypeScript]
class OperatorOrderData extends Data
{
    public function __construct(
        public OrderData $order,
        public ?string $paymentState,
        public ?string $refundedAt,
        public int $refundedCents,
        public ?string $posProvider,
        public ?string $posPushedAt,
        public ?string $updatedAt,
        #[DataCollectionOf(OrderEventData::class)]
        /** @var array<int, OrderEventData> Newest first. */
        public array $events,
    ) {}

    public static function fromModel(Order $order): self
    {
        $order->loadMissing(['items', 'deliveryAssignment', 'events.user']);

        return new self(
            order: OrderData::fromModel($order),
            paymentState: $order->payment_state?->value,
            refundedAt: $order->refunded_at?->toIso8601String(),
            refundedCents: (int) ($order->refunded_cents ?? 0),
            posProvider: $order->pos_provider?->value,
            posPushedAt: $order->pos_pushed_at?->toIso8601String(),
            updatedAt: $order->updated_at?->toIso8601String(),
            events: $order->events
                ->sortBy([['occurred_at', 'desc'], ['id', 'desc']])
                ->values()
                ->map(fn (OrderEvent $e) => OrderEventData::fromModel($e))
                ->all(),
        );
    }
}
