<?php

namespace App\Models;

use App\Enums\DeliveryProviderName;
use App\Services\Delivery\DeliveryQuote as DeliveryQuoteValue;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A delivery quote taken at checkout, before payment.
 *
 * Durable rather than cached because it does three jobs a cache does badly: it
 * is the source of truth for a **price the customer is about to be charged**
 * (so it can never come back from the client), it holds the exact address
 * payload for the byte-identical replay rule, and it is the raw material for
 * the fee-drift measurement that decides whether absorbing restaurants ever
 * need an exposure cap.
 *
 * Not to be confused with {@see DeliveryQuoteValue}, the provider-facing value
 * object. This is its persisted form.
 */
class DeliveryQuote extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider' => DeliveryProviderName::class,
            'dropoff_address' => 'array',
            'expires_at' => 'datetime',
            'dropoff_eta_at' => 'datetime',
            'dropoff_deadline_at' => 'datetime',
        ];
    }

    /**
     * Persist a provider quote for a restaurant + address.
     *
     * @param  array<string, mixed>  $dropoffAddress
     */
    public static function record(
        Restaurant $restaurant,
        DeliveryQuoteValue $quote,
        array $dropoffAddress,
    ): self {
        return self::create([
            'token' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'provider' => $quote->provider,
            'external_quote_id' => $quote->externalQuoteId,
            'dropoff_address' => $dropoffAddress,
            'dropoff_address_payload' => $quote->dropoffAddressPayload,
            'pickup_address_payload' => $quote->pickupAddressPayload,
            'fee_cents' => $quote->feeCents,
            'eta_minutes' => $quote->etaMinutes,
            'pickup_duration_minutes' => $quote->pickupDurationMinutes,
            'dropoff_eta_at' => $quote->dropoffEtaAt,
            'dropoff_deadline_at' => $quote->dropoffDeadlineAt,
            'expires_at' => $quote->expiresAt,
        ]);
    }

    /**
     * A quote with no expiry never expires — that is self-delivery, whose fee
     * is the restaurant's own number and cannot go stale.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this quote was issued for the address now being checked out with.
     * Guards against a customer quoting one address and ordering to another.
     *
     * @param  array<string, mixed>  $address
     */
    public function matchesAddress(array $address): bool
    {
        return $this->comparableAddress((array) $this->dropoff_address)
            === $this->comparableAddress($address);
    }

    /**
     * Back into the provider value object, so dispatch can replay this quote
     * without re-deriving anything.
     */
    public function toValueObject(): DeliveryQuoteValue
    {
        return new DeliveryQuoteValue(
            provider: $this->provider,
            feeCents: (int) $this->fee_cents,
            etaMinutes: $this->eta_minutes,
            expiresAt: $this->expires_at ? CarbonImmutable::parse($this->expires_at) : null,
            externalQuoteId: $this->external_quote_id,
            dropoffEtaAt: $this->dropoff_eta_at ? CarbonImmutable::parse($this->dropoff_eta_at) : null,
            dropoffDeadlineAt: $this->dropoff_deadline_at ? CarbonImmutable::parse($this->dropoff_deadline_at) : null,
            pickupDurationMinutes: $this->pickup_duration_minutes,
            dropoffAddressPayload: $this->dropoff_address_payload,
            pickupAddressPayload: $this->pickup_address_payload,
        );
    }

    /**
     * Only the fields that decide where a courier drives. Delivery instructions
     * are excluded deliberately: editing "leave at the door" must not silently
     * invalidate a quote and re-price the order.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, string>
     */
    private function comparableAddress(array $address): array
    {
        return [
            'street' => trim((string) ($address['street'] ?? '')),
            'street2' => trim((string) ($address['street2'] ?? '')),
            'city' => trim((string) ($address['city'] ?? '')),
            'state' => trim((string) ($address['state'] ?? '')),
            'postal_code' => trim((string) ($address['postal_code'] ?? '')),
        ];
    }
}
