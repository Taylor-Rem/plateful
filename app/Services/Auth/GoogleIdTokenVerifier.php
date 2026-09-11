<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;

/**
 * Verifies a Google ID token obtained natively in the mobile app. Accepts the
 * app's own OAuth client ids plus the web client id (Google issues the token
 * for whichever client the app signed in with).
 */
class GoogleIdTokenVerifier extends JwksIdTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    /**
     * @return array<int, string>
     */
    protected function issuers(): array
    {
        return ['https://accounts.google.com', 'accounts.google.com'];
    }

    /**
     * @return array<int, string>
     */
    public function audiences(): array
    {
        return array_values(array_filter([
            ...(array) config('services.google.app_client_ids', []),
            config('services.google.client_id'),
        ]));
    }

    /**
     * @throws InvalidIdTokenException
     */
    public function verify(string $idToken): SocialIdentity
    {
        $claims = $this->claims($idToken);

        return new SocialIdentity(
            provider: SocialProvider::Google,
            id: $claims->sub,
            email: isset($claims->email) ? (string) $claims->email : null,
            emailVerified: $this->emailVerifiedClaim($claims),
            name: isset($claims->name) ? (string) $claims->name : null,
            avatar: isset($claims->picture) ? (string) $claims->picture : null,
        );
    }
}
