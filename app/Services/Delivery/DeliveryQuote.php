<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryProviderName;
use Carbon\CarbonImmutable;

class DeliveryQuote
{
    /**
     * @param  int  $feeCents  what the provider will charge the restaurant
     * @param  int|null  $etaMinutes  end-to-end duration, order to doorstep
     * @param  int|null  $pickupDurationMinutes  how long until a courier reaches the kitchen
     * @param  string|null  $dropoffAddressPayload  the EXACT provider-encoded dropoff address this
     *                                              quote was issued for. Uber rejects a create whose
     *                                              address differs from its quote's, so this is
     *                                              replayed verbatim rather than re-encoded.
     */
    public function __construct(
        public readonly DeliveryProviderName $provider,
        public readonly int $feeCents,
        public readonly ?int $etaMinutes = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $externalQuoteId = null,
        public readonly ?CarbonImmutable $dropoffEtaAt = null,
        public readonly ?CarbonImmutable $dropoffDeadlineAt = null,
        public readonly ?int $pickupDurationMinutes = null,
        public readonly ?string $dropoffAddressPayload = null,
        public readonly ?string $pickupAddressPayload = null,
    ) {}
}
