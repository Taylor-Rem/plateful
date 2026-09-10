<?php

namespace App\Data;

use App\Models\MenuItemIngredient;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuItemIngredientData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $position,
        public bool $isRemovable,
        public bool $allowHalf,
        public ?int $extraPriceCents,
        public ?int $swapTemplateId,
    ) {}

    public static function fromModel(MenuItemIngredient $ingredient): self
    {
        return new self(
            id: $ingredient->id,
            name: $ingredient->name,
            position: $ingredient->position,
            isRemovable: $ingredient->is_removable,
            allowHalf: $ingredient->allow_half,
            extraPriceCents: $ingredient->extra_price_cents,
            swapTemplateId: $ingredient->swap_template_id,
        );
    }
}
