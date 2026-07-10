<?php

namespace App\Services\Pos;

use App\Enums\PosProviderName;

class PosPushResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $ticketId = null,
        public readonly ?PosProviderName $provider = null,
        public readonly ?string $failureReason = null,
        public readonly bool $tokenExpired = false,
    ) {}

    public static function ok(PosProviderName $provider, string $ticketId): self
    {
        return new self(success: true, ticketId: $ticketId, provider: $provider);
    }

    public static function failed(?PosProviderName $provider, string $reason, bool $tokenExpired = false): self
    {
        return new self(
            success: false,
            provider: $provider,
            failureReason: $reason,
            tokenExpired: $tokenExpired,
        );
    }
}
