<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Outcome of rebuilding a cart from a past order. `skipped` names the lines
 * that could not come back (item gone, unavailable, or its options changed)
 * so the app can say so instead of silently shrinking the order.
 */
#[TypeScript]
class ReorderResultData extends Data
{
    /**
     * @param  array<int, array{name: string, reason: string}>  $skipped
     */
    public function __construct(
        public ?CartData $cart,
        public ?string $cartToken,
        public array $skipped,
    ) {}
}
