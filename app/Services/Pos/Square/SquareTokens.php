<?php

namespace App\Services\Pos\Square;

use Carbon\CarbonImmutable;

/**
 * The credentials Square hands back from an OAuth token exchange or refresh.
 */
class SquareTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly CarbonImmutable $expiresAt,
        public readonly string $merchantId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the decoded Square token response
     */
    public static function fromResponse(array $payload): self
    {
        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: (string) $payload['refresh_token'],
            expiresAt: CarbonImmutable::parse((string) $payload['expires_at']),
            merchantId: (string) $payload['merchant_id'],
        );
    }
}
