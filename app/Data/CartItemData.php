<?php

namespace App\Data;

use App\Models\CartItem;
use App\Models\ItemTemplateOption;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CartItemData extends Data
{
    public function __construct(
        public int $id,
        public int $menuItemId,
        public string $menuItemName,
        public ?string $imageThumbUrl,
        public int $quantity,
        public int $unitPriceCents,
        public int $lineTotalCents,
        public string $selectionSummary,
        /** @var array<int, array{groupName: string, selectionNames: array<int, string>}> */
        public array $selectionGroups,
        public bool $isAvailable,
    ) {}

    public static function fromModel(CartItem $item): self
    {
        $menuItem = $item->menuItem;
        $modifiers = $item->modifiers ?? null;

        $groups = [];
        $summaryParts = [];
        $allOptionsAvailable = true;

        if (is_array($modifiers) && isset($modifiers['groups']) && is_array($modifiers['groups'])) {
            $optionIds = [];
            foreach ($modifiers['groups'] as $g) {
                if (! is_array($g) || ! isset($g['selections']) || ! is_array($g['selections'])) {
                    continue;
                }
                $names = [];
                foreach ($g['selections'] as $sel) {
                    if (isset($sel['option_name'])) {
                        $names[] = (string) $sel['option_name'];
                    }
                    if (isset($sel['option_id'])) {
                        $optionIds[] = (int) $sel['option_id'];
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

            if ($optionIds !== []) {
                $available = ItemTemplateOption::query()
                    ->whereIn('id', $optionIds)
                    ->pluck('is_available', 'id');

                foreach ($optionIds as $oid) {
                    if (! ($available[$oid] ?? false)) {
                        $allOptionsAvailable = false;
                        break;
                    }
                }
            }
        }

        $isAvailable = $menuItem !== null
            && (bool) $menuItem->is_available
            && $allOptionsAvailable;

        return new self(
            id: $item->id,
            menuItemId: $item->menu_item_id,
            menuItemName: $menuItem?->name ?? 'Item',
            imageThumbUrl: $menuItem?->imageThumbUrl(),
            quantity: (int) $item->quantity,
            unitPriceCents: (int) $item->unit_price_cents,
            lineTotalCents: (int) $item->unit_price_cents * (int) $item->quantity,
            selectionSummary: implode(' · ', $summaryParts),
            selectionGroups: $groups,
            isAvailable: $isAvailable,
        );
    }
}
