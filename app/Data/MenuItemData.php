<?php

namespace App\Data;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuItemData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public int $priceCents,
        public ?string $imageUrl,
        public bool $isAvailable,
        #[DataCollectionOf(MenuItemModifierData::class)]
        /** @var array<int, MenuItemModifierData> */
        public array $modifiers,
    ) {}

    public static function fromModel(MenuItem $item): self
    {
        return new self(
            id: $item->id,
            name: $item->name,
            slug: $item->slug,
            description: $item->description,
            priceCents: $item->price_cents,
            imageUrl: $item->image_path ? Storage::url($item->image_path) : null,
            isAvailable: $item->is_available,
            modifiers: $item->modifiers
                ->map(fn ($m) => MenuItemModifierData::fromModel($m))
                ->all(),
        );
    }
}
