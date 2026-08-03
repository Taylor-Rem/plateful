<?php

namespace App\Data;

use App\Models\DeliveryAssignment;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DeliveryAssignmentData extends Data
{
    public function __construct(
        public string $provider,
        /** Plateful's mapped lifecycle — drives logic (badges, polling). */
        public string $status,
        /** The provider's raw status word, e.g. `enroute_to_dropoff`. */
        public ?string $providerStatus,
        /** Human-readable status, preferring the raw provider word. */
        public string $statusLabel,
        /** Whether the delivery is still in flight (worth polling for). */
        public bool $isActive,
        public ?string $trackingUrl,
        /** The id the provider's support desk keys on (DoorDash). */
        public ?string $supportReference,
        /** Our delivery id at the provider (`external_delivery_id`). */
        public ?string $externalId,
        public ?string $driverName,
        public ?string $driverPhone,
        public ?string $pickupEtaAt,
        public ?string $dropoffEtaAt,
        public ?string $updatedAt,
    ) {}

    public static function fromModel(DeliveryAssignment $assignment): self
    {
        return new self(
            provider: $assignment->provider->value,
            status: $assignment->status->value,
            providerStatus: $assignment->provider_status,
            statusLabel: self::labelFor($assignment),
            isActive: ! in_array($assignment->status->value, ['delivered', 'cancelled', 'failed'], true),
            trackingUrl: $assignment->tracking_url,
            supportReference: $assignment->support_reference,
            externalId: $assignment->external_id,
            driverName: $assignment->driver_name,
            driverPhone: $assignment->driver_phone,
            pickupEtaAt: $assignment->pickup_eta_at?->toIso8601String(),
            dropoffEtaAt: $assignment->dropoff_eta_at?->toIso8601String(),
            updatedAt: $assignment->updated_at?->toIso8601String(),
        );
    }

    /**
     * The raw provider word carries more nuance than the mapped enum
     * (`enroute_to_dropoff` vs a flat `picked_up`), so prefer it for display
     * without touching the money logic that depends on the mapped status.
     */
    private static function labelFor(DeliveryAssignment $assignment): string
    {
        return Str::of($assignment->provider_status ?? $assignment->status->value)
            ->replace('_', ' ')
            ->ucfirst()
            ->toString();
    }
}
