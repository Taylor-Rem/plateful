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
        public bool $isFeatured,
        public int $position,
        /**
         * Reusable templates attached to the item, in order.
         *
         * @var array<int, int>
         */
        public array $templateIds,
        /** Every configurable group, templates first then the item's own compiled groups. */
        #[DataCollectionOf(ItemTemplateGroupData::class)]
        /** @var array<int, ItemTemplateGroupData> */
        public array $groups,
        #[DataCollectionOf(MenuItemIngredientData::class)]
        /** @var array<int, MenuItemIngredientData> */
        public array $ingredients,
        /** @var array<int, int> */
        public array $defaultSelectionIds,
    ) {}

    public static function fromModel(MenuItem $item): self
    {
        $templates = $item->relationLoaded('templates') ? $item->templates : $item->templates()->with('groups.options')->get();
        $ingredients = $item->relationLoaded('ingredients') ? $item->ingredients : $item->ingredients()->get();

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
            isFeatured: (bool) $item->is_featured,
            position: $item->position,
            templateIds: $templates->map(fn ($t) => (int) $t->id)->values()->all(),
            groups: $item->optionGroups()
                ->map(fn ($g) => ItemTemplateGroupData::fromModel($g))
                ->values()
                ->all(),
            ingredients: $ingredients
                ->map(fn ($i) => MenuItemIngredientData::fromModel($i))
                ->values()
                ->all(),
            defaultSelectionIds: $item->defaultSelections
                ->map(fn ($o) => (int) $o->id)
                ->values()
                ->all(),
        );
    }
}
