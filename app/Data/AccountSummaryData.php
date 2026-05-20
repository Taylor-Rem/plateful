<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AccountSummaryData extends Data
{
    public function __construct(
        public string $userName,
        public string $userEmail,
        public ?string $userPhone,
        public int $orderCount,
        public int $addressCount,
        public int $loyaltyPoints,
        public ?AddressData $defaultAddress,
    ) {}
}
