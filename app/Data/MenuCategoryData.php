<?php

namespace App\Data;

use App\Models\MenuCategory;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuCategoryData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public int $position,
        #[DataCollectionOf(MenuItemData::class)]
        /** @var array<int, MenuItemData> */
        public array $items,
    ) {}

    public static function fromModel(MenuCategory $category): self
    {
        return new self(
            id: $category->id,
            name: $category->name,
            slug: $category->slug,
            description: $category->description,
            position: $category->position,
            items: $category->items
                ->map(fn ($i) => MenuItemData::fromModel($i))
                ->all(),
        );
    }
}
