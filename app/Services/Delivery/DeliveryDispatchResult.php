<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryProviderName;
use App\Models\DeliveryAssignment;

class DeliveryDispatchResult
{
    /**
     * @param  array<int, string>  $attemptedProviders
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?DeliveryAssignment $assignment = null,
        public readonly ?DeliveryProviderName $provider = null,
        public readonly array $attemptedProviders = [],
        public readonly ?string $failureReason = null,
    ) {}

    /**
     * @param  array<int, string>  $attempted
     */
    public static function failed(array $attempted, string $reason): self
    {
        return new self(success: false, attemptedProviders: $attempted, failureReason: $reason);
    }

    /**
     * @param  array<int, string>  $attempted
     */
    public static function ok(DeliveryAssignment $assignment, DeliveryProviderName $provider, array $attempted): self
    {
        return new self(
            success: true,
            assignment: $assignment,
            provider: $provider,
            attemptedProviders: $attempted,
        );
    }
}
