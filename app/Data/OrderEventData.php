<?php

namespace App\Data;

use App\Models\OrderEvent;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OrderEventData extends Data
{
    public function __construct(
        public int $id,
        public ?string $fromStatus,
        public string $toStatus,
        public string $occurredAt,
        public ?string $userName,
        public ?string $note,
    ) {}

    public static function fromModel(OrderEvent $event): self
    {
        return new self(
            id: $event->id,
            fromStatus: $event->from_status?->value,
            toStatus: $event->to_status->value,
            occurredAt: $event->occurred_at->toIso8601String(),
            userName: $event->user?->name,
            note: $event->note,
        );
    }
}
