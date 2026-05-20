<?php

namespace App\Data;

use App\Models\ItemTemplateGroup;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ItemTemplateGroupData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $minSelections,
        public ?int $maxSelections,
        public int $position,
        public bool $isSingleSelect,
        public bool $isRequired,
        #[DataCollectionOf(ItemTemplateOptionData::class)]
        /** @var array<int, ItemTemplateOptionData> */
        public array $options,
    ) {}

    public static function fromModel(ItemTemplateGroup $group): self
    {
        return new self(
            id: $group->id,
            name: $group->name,
            minSelections: $group->min_selections,
            maxSelections: $group->max_selections,
            position: $group->position,
            isSingleSelect: $group->isSingleSelect(),
            isRequired: $group->isRequired(),
            options: $group->options
                ->map(fn ($o) => ItemTemplateOptionData::fromModel($o))
                ->all(),
        );
    }
}
