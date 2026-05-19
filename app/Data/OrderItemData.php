<?php

namespace App\Data;

use App\Models\OrderItem;
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
    ) {}

    public static function fromModel(OrderItem $item): self
    {
        $groups = [];
        $summaryParts = [];

        $modifiers = $item->modifiers;
        if (is_array($modifiers) && isset($modifiers['groups']) && is_array($modifiers['groups'])) {
            foreach ($modifiers['groups'] as $g) {
                if (! is_array($g) || ! isset($g['selections']) || ! is_array($g['selections'])) {
                    continue;
                }
                $names = [];
                foreach ($g['selections'] as $sel) {
                    if (isset($sel['option_name'])) {
                        $names[] = (string) $sel['option_name'];
                    }
                }
                $groups[] = [
                    'groupName' => (string) ($g['group_name'] ?? ''),
                    'selectionNames' => $names,
                ];
                foreach ($names as $n) {
                    $summaryParts[] = $n;
                }
            }
        }

        return new self(
            id: $item->id,
            name: $item->name,
            quantity: (int) $item->quantity,
            unitPriceCents: (int) $item->unit_price_cents,
            subtotalCents: (int) $item->subtotal_cents,
            modifierSummary: implode(' · ', $summaryParts),
            modifierGroups: $groups,
        );
    }
}
