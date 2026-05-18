<?php

namespace App\Data;

use App\Models\MenuItem;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuItemData extends Data
{
    public function __construct(
        public int $id,
        public int $menuCategoryId,
        public string $name,
        public string $slug,
        public ?string $description,
        public int $priceCents,
        public ?string $imageUrl,
        public ?string $imageMediumUrl,
        public ?string $imageThumbUrl,
        public bool $isAvailable,
        public int $position,
        #[DataCollectionOf(MenuItemModifierData::class)]
        /** @var array<int, MenuItemModifierData> */
        public array $modifiers,
    ) {}

    public static function fromModel(MenuItem $item): self
    {
        return new self(
            id: $item->id,
            menuCategoryId: $item->menu_category_id,
            name: $item->name,
            slug: $item->slug,
            description: $item->description,
            priceCents: $item->price_cents,
            imageUrl: $item->imageUrl(),
            imageMediumUrl: $item->imageMediumUrl(),
            imageThumbUrl: $item->imageThumbUrl(),
            isAvailable: $item->is_available,
            position: $item->position,
            modifiers: $item->modifiers
                ->map(fn ($m) => MenuItemModifierData::fromModel($m))
                ->all(),
        );
    }
}
