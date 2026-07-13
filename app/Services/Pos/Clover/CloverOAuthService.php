<?php

namespace App\Services\Pos\Clover;

use App\Exceptions\PosProviderException;

/**
 * Handles the Clover v2/OAuth handshake: building the authorize URL, exchanging
 * the returned code for an expiring token pair, and refreshing it. Order pushing
 * lives in CloverPosProvider; this class only manages credentials.
 *
 * Two Clover-specific notes vs. Square:
 * - Clover has no `scope` parameter; the app's permissions (Orders read/write,
 *   Inventory read) are configured in the Clover developer dashboard instead.
 * - The refresh token is single-use: every refresh rotates BOTH tokens, so the
 *   caller must persist the new pair (see CloverPosProvider::freshAccessToken).
 */
class CloverOAuthService
{
    public function __construct(private CloverClient $client) {}

    /**
     * The permissions we rely on. Clover does not accept these in the authorize
     * URL — they must be enabled on the app in the developer dashboard — but we
     * store them on the integration for later reference, mirroring Square.
     *
     * @var list<string>
     */
    private const PERMISSIONS = [
        'ORDERS_WRITE',
        'ORDERS_READ',
        'MERCHANTS_READ',
        'INVENTORY_READ',
    ];

    /**
     * The permissions we expect the app to have, stored on the integration.
     *
     * @return list<string>
     */
    public function requestedScopes(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * The URL the owner is sent to so they can authorize Plateful. `state` is an
     * opaque, single-use token we mint and later verify on the callback.
     */
    public function buildAuthorizeUrl(string $state): string
    {
        return $this->client->authorizeHost().'/oauth/v2/authorize?'.http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * Trade the authorization code from the callback for an access/refresh pair.
     */
    public function exchangeCode(string $code): CloverTokens
    {
        $response = $this->client->base()->post('/oauth/v2/token', [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw PosProviderException::oauthFailed('Clover token exchange failed: '.$response->body());
        }

        return CloverTokens::fromResponse($response->json());
    }

    /**
     * Swap a refresh token for a fresh pair. Clover's refresh endpoint takes only
     * the client id and refresh token (no secret) and rotates the refresh token.
     */
    public function refreshToken(string $refreshToken): CloverTokens
    {
        $response = $this->client->base()->post('/oauth/v2/refresh', [
            'client_id' => $this->appId(),
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw PosProviderException::oauthFailed('Clover token refresh failed: '.$response->body());
        }

        return CloverTokens::fromResponse($response->json());
    }

    private function appId(): string
    {
        return (string) config('services.clover.app_id');
    }

    private function appSecret(): string
    {
        return (string) config('services.clover.app_secret');
    }

    private function redirectUri(): string
    {
        return (string) config('services.clover.redirect');
    }
}
