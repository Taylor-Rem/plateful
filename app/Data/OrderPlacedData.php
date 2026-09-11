<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A freshly materialised order plus the token that lets a guest keep
 * reading it (sent back as X-Order-Token — the app's stand-in for the web
 * confirmation cookie). Signed-in customers can ignore the token.
 */
#[TypeScript]
class OrderPlacedData extends Data
{
    public function __construct(
        public OrderData $order,
        public string $confirmationToken,
    ) {}
}
