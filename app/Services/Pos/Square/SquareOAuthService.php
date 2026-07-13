<?php

namespace App\Services\Pos\Square;

use App\Exceptions\PosProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Handles the Square OAuth handshake: building the authorize URL, exchanging
 * the returned code for tokens, refreshing them, and revoking on disconnect.
 * Order pushing lives in SquarePosProvider; this class only manages credentials.
 */
class SquareOAuthService
{
    /**
     * Square API version pinned for every request. Bump deliberately after
     * reading the Square changelog — never float it.
     */
    public const API_VERSION = '2025-06-18';

    /**
     * Scopes requested from the merchant. ORDERS_* to inject tickets,
     * MERCHANT_PROFILE_READ to resolve the location, ITEMS_READ for the future
     * catalog matcher (§2b).
     *
     * @var list<string>
     */
    private const SCOPES = [
        'ORDERS_WRITE',
        'ORDERS_READ',
        'MERCHANT_PROFILE_READ',
        'ITEMS_READ',
    ];

    /**
     * The scopes we request, stored on the integration for later reference.
     *
     * @return list<string>
     */
    public function requestedScopes(): array
    {
        return self::SCOPES;
    }

    /**
     * The URL the owner is sent to so they can authorize Plateful. `state` is
     * an opaque, single-use token we mint and later verify on the callback.
     */
    public function buildAuthorizeUrl(string $state): string
    {
        return $this->host().'/oauth2/authorize?'.http_build_query([
            'client_id' => $this->applicationId(),
            'scope' => implode(' ', self::SCOPES),
            'session' => 'false',
            'state' => $state,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Trade the authorization code from the callback for access/refresh tokens.
     */
    public function exchangeCode(string $code): SquareTokens
    {
        $response = $this->client()->post('/oauth2/token', [
            'client_id' => $this->applicationId(),
            'client_secret' => $this->applicationSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);

        if ($response->failed()) {
            throw PosProviderException::oauthFailed('Square token exchange failed: '.$response->body());
        }

        return SquareTokens::fromResponse($response->json());
    }

    /**
     * Swap a refresh token for a fresh access token before it expires.
     */
    public function refreshToken(string $refreshToken): SquareTokens
    {
        $response = $this->client()->post('/oauth2/token', [
            'client_id' => $this->applicationId(),
            'client_secret' => $this->applicationSecret(),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw PosProviderException::oauthFailed('Square token refresh failed: '.$response->body());
        }

        return SquareTokens::fromResponse($response->json());
    }

    /**
     * The merchant's primary active location id, needed to attach orders.
     * Returns null if the merchant has no active location.
     */
    public function fetchPrimaryLocationId(string $accessToken): ?string
    {
        $response = $this->client()
            ->withToken($accessToken)
            ->get('/v2/locations');

        if ($response->failed()) {
            throw PosProviderException::oauthFailed('Square location lookup failed: '.$response->body());
        }

        /** @var array<int, array{id?: string, status?: string}> $locations */
        $locations = $response->json('locations', []);

        $active = collect($locations)->firstWhere('status', 'ACTIVE');

        return $active['id'] ?? ($locations[0]['id'] ?? null);
    }

    /**
     * Revoke the merchant's access token so a disconnect is honored on Square's
     * side too. Best-effort: a failed revoke still lets us drop our own record.
     */
    public function revoke(string $accessToken): bool
    {
        $response = $this->client()
            ->withHeaders(['Authorization' => 'Client '.$this->applicationSecret()])
            ->post('/oauth2/revoke', [
                'client_id' => $this->applicationId(),
                'access_token' => $accessToken,
            ]);

        return $response->successful();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->host())
            ->acceptJson()
            ->withHeaders(['Square-Version' => self::API_VERSION])
            ->timeout(15);
    }

    private function host(): string
    {
        return $this->environment() === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function environment(): string
    {
        return (string) config('services.square.environment', 'sandbox');
    }

    private function applicationId(): string
    {
        return (string) config('services.square.application_id');
    }

    private function applicationSecret(): string
    {
        return (string) config('services.square.application_secret');
    }

    private function redirectUri(): string
    {
        return (string) config('services.square.redirect');
    }
}
