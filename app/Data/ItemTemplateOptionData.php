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
        public int $priceDeltaCents,
        public bool $isAvailable,
        public int $position,
    ) {}

    public static function fromModel(ItemTemplateOption $option): self
    {
        return new self(
            id: $option->id,
            name: $option->name,
            priceDeltaCents: $option->price_delta_cents,
            isAvailable: $option->is_available,
            position: $option->position,
        );
    }
}
