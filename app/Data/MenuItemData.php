<?php

namespace App\Data;

use App\Models\MenuItem;
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
        public ?int $itemTemplateId,
        public ?ItemTemplateData $template,
        /** @var array<int, int> */
        public array $defaultSelectionIds,
    ) {}

    public static function fromModel(MenuItem $item): self
    {
        $template = $item->relationLoaded('template') ? $item->template : $item->template;

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
            itemTemplateId: $item->item_template_id,
            template: $template ? ItemTemplateData::fromModel($template) : null,
            defaultSelectionIds: $item->defaultSelections
                ->map(fn ($o) => (int) $o->id)
                ->values()
                ->all(),
        );
    }
}
