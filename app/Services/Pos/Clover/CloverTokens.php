<?php

namespace App\Services\Pos\Clover;

use Carbon\CarbonImmutable;

/**
 * The credentials Clover hands back from a v2/OAuth token exchange or refresh.
 * Clover reports expirations as Unix timestamps (seconds) and — unlike Square —
 * does NOT return the merchant id here; that arrives as a callback query param.
 */
class CloverTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the decoded Clover token response
     */
    public static function fromResponse(array $payload): self
    {
        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: (string) $payload['refresh_token'],
            expiresAt: CarbonImmutable::createFromTimestamp((int) $payload['access_token_expiration']),
        );
    }
}
