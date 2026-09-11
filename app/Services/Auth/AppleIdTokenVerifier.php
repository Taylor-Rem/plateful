<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;

/**
 * Verifies a Sign in with Apple identity token. Apple's token carries the
 * email (real or private relay) every time but never the name — the app
 * receives the name once, on first authorization, and passes it alongside.
 */
class AppleIdTokenVerifier extends JwksIdTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    /**
     * @return array<int, string>
     */
    protected function issuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    /**
     * @return array<int, string>
     */
    public function audiences(): array
    {
        return array_values(array_filter((array) config('services.apple.client_ids', [])));
    }

    /**
     * @throws InvalidIdTokenException
     */
    public function verify(string $idToken, ?string $name = null): SocialIdentity
    {
        $claims = $this->claims($idToken);

        return new SocialIdentity(
            provider: SocialProvider::Apple,
            id: $claims->sub,
            email: isset($claims->email) ? (string) $claims->email : null,
            emailVerified: $this->emailVerifiedClaim($claims),
            name: $name !== null && trim($name) !== '' ? trim($name) : null,
        );
    }
}
