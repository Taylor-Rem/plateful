<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryProviderName;
use Carbon\CarbonImmutable;

class DeliveryQuote
{
    public function __construct(
        public readonly DeliveryProviderName $provider,
        public readonly int $feeCents,
        public readonly ?int $etaMinutes = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $externalQuoteId = null,
    ) {}
}
