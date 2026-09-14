<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One person's line on the monthly payout sheet.
 */
#[TypeScript]
class EarnerData extends Data
{
    /**
     * @param  array<string, int>  $roles  RevenueRole value => cents earned in that role
     */
    public function __construct(
        /** Null when the earner's account was removed; the money is still owed. */
        public ?int $userId,
        public string $name,
        public ?string $email,
        public array $roles,
        public int $totalCents,
    ) {}
}
