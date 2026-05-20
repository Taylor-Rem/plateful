<?php

namespace App\Data;

use App\Models\Cart;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CartData extends Data
{
    public function __construct(
        public int $id,
        public int $itemCount,
        public int $subtotalCents,
        #[DataCollectionOf(CartItemData::class)]
        /** @var array<int, CartItemData> */
        public array $items,
    ) {}

    public static function fromModel(Cart $cart): self
    {
        $cart->loadMissing(['items.menuItem']);

        $items = $cart->items
            ->map(fn ($i) => CartItemData::fromModel($i))
            ->values()
            ->all();

        $itemCount = 0;
        $subtotal = 0;
        foreach ($items as $i) {
            $itemCount += $i->quantity;
            $subtotal += $i->lineTotalCents;
        }

        return new self(
            id: $cart->id,
            itemCount: $itemCount,
            subtotalCents: $subtotal,
            items: $items,
        );
    }
}
