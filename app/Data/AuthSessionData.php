<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A completed API sign-in: the device's bearer token plus the account.
 */
#[TypeScript]
class AuthSessionData extends Data
{
    public function __construct(
        public string $token,
        public MeData $user,
    ) {}
}
