<?php

namespace App\Data;

use App\Models\ItemTemplate;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ItemTemplateData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public bool $isActive,
        public int $position,
        #[DataCollectionOf(ItemTemplateGroupData::class)]
        /** @var array<int, ItemTemplateGroupData> */
        public array $groups,
    ) {}

    public static function fromModel(ItemTemplate $template): self
    {
        return new self(
            id: $template->id,
            name: $template->name,
            description: $template->description,
            isActive: $template->is_active,
            position: $template->position,
            groups: $template->groups
                ->map(fn ($g) => ItemTemplateGroupData::fromModel($g))
                ->all(),
        );
    }
}
