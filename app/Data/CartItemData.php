<?php

namespace App\Data;

use App\Models\CartItem;
use App\Models\ItemTemplateOption;
use App\Support\Menus\ModifierSummary;
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
        /** @var array<int, int> */
        public array $selectedOptionIds,
        public ?string $notes,
        public bool $isAvailable,
    ) {}

    public static function fromModel(CartItem $item): self
    {
        $menuItem = $item->menuItem;
        $modifiers = $item->modifiers ?? null;

        $groups = ModifierSummary::groups($modifiers);
        $optionIds = ModifierSummary::selectedOptionIds($modifiers);

        $allOptionsAvailable = true;
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
            selectionSummary: ModifierSummary::summary($modifiers),
            selectionGroups: $groups,
            selectedOptionIds: $optionIds,
            notes: $item->notes,
            isAvailable: $isAvailable,
        );
    }
}
