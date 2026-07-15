<?php

namespace App\Services\Delivery\UberDirect;

use App\Services\Pos\Square\SquareTokens;
use Carbon\CarbonImmutable;

/**
 * The credential Uber hands back from a client_credentials grant. No refresh
 * token, unlike {@see SquareTokens} — you re-run the grant with the same client
 * id/secret instead.
 */
class UberDirectToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the decoded Uber token response
     */
    public static function fromResponse(array $payload): self
    {
        return new self(
            accessToken: (string) $payload['access_token'],
            // Uber documents 30 days (2,592,000s) but sends expires_in on every
            // response — trust the response and fall back to the documented
            // value only if it's absent.
            expiresAt: CarbonImmutable::now()->addSeconds(
                (int) ($payload['expires_in'] ?? UberDirectTokenService::DOCUMENTED_LIFETIME_SECONDS)
            ),
        );
    }
}
