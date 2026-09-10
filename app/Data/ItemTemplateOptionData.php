<?php

namespace App\Data;

use App\Models\ItemTemplateOption;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ItemTemplateOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        /** choice | included | extra */
        public string $kind,
        public ?int $ingredientId,
        public int $priceDeltaCents,
        public bool $isAvailable,
        public int $position,
    ) {}

    public static function fromModel(ItemTemplateOption $option): self
    {
        return new self(
            id: $option->id,
            name: $option->name,
            kind: (string) ($option->kind ?? ItemTemplateOption::KIND_CHOICE),
            ingredientId: $option->menu_item_ingredient_id === null ? null : (int) $option->menu_item_ingredient_id,
            priceDeltaCents: $option->price_delta_cents,
            isAvailable: $option->is_available,
            position: $option->position,
        );
    }
}
