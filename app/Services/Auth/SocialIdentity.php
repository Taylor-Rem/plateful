<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;

/**
 * What a verified social sign-in tells us about the person, independent of
 * whether it arrived through Socialite's web redirect or a mobile ID token.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public SocialProvider $provider,
        public string $id,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        public ?string $avatar = null,
    ) {}
}
