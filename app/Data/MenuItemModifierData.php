<?php

namespace App\Data;

use App\Models\MenuItemModifier;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuItemModifierData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $groupLabel,
        public int $priceDeltaCents,
        public bool $isDefault,
    ) {}

    public static function fromModel(MenuItemModifier $modifier): self
    {
        return new self(
            id: $modifier->id,
            name: $modifier->name,
            groupLabel: $modifier->group_label,
            priceDeltaCents: $modifier->price_delta_cents,
            isDefault: $modifier->is_default,
        );
    }
}
