<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Sign-in paused at the two-factor step (HTTP 202). The challenge token is
 * short-lived and only good for POST /api/v1/auth/two-factor.
 */
#[TypeScript]
class TwoFactorChallengeData extends Data
{
    public function __construct(
        public bool $twoFactorRequired,
        public string $challengeToken,
    ) {}
}
