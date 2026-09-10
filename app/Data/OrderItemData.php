<?php

namespace App\Data;

use App\Models\OrderItem;
use App\Support\Menus\ModifierSummary;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OrderItemData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $quantity,
        public int $unitPriceCents,
        public int $subtotalCents,
        public string $modifierSummary,
        /** @var array<int, array{groupName: string, selectionNames: array<int, string>}> */
        public array $modifierGroups,
        public ?string $notes,
    ) {}

    public static function fromModel(OrderItem $item): self
    {
        $modifiers = $item->modifiers;

        return new self(
            id: $item->id,
            name: $item->name,
            quantity: (int) $item->quantity,
            unitPriceCents: (int) $item->unit_price_cents,
            subtotalCents: (int) $item->subtotal_cents,
            modifierSummary: ModifierSummary::summary($modifiers),
            modifierGroups: ModifierSummary::groups($modifiers),
            notes: $item->notes,
        );
    }
}
