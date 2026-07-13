<?php

namespace App\Services\Pos\Clover;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Owns the Clover HTTP surface. Unlike Square, Clover splits two hosts: the
 * browser-facing OAuth *authorize* host (where the owner grants access) and the
 * *API* host (token exchange, refresh, and the Orders API). Both are selected by
 * the configured environment. Only North America is wired here; EU/LA hosts are
 * a later addition if we sell outside NA.
 */
class CloverClient
{
    /**
     * The host the owner's browser is sent to for the OAuth authorize screen.
     */
    public function authorizeHost(): string
    {
        return $this->environment() === 'production'
            ? 'https://www.clover.com'
            : 'https://sandbox.dev.clover.com';
    }

    /**
     * The API host for token exchange/refresh and the v3 Orders API.
     */
    public function apiHost(): string
    {
        return $this->environment() === 'production'
            ? 'https://api.clover.com'
            : 'https://apisandbox.dev.clover.com';
    }

    /**
     * An unauthenticated request against the API host — used for the OAuth
     * token/refresh endpoints, which authenticate via credentials in the body.
     */
    public function base(): PendingRequest
    {
        return $this->pending();
    }

    /**
     * A request authenticated as the merchant with their access token.
     */
    public function authed(string $accessToken): PendingRequest
    {
        return $this->pending()->withToken($accessToken);
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl($this->apiHost())
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    private function environment(): string
    {
        return (string) config('services.clover.environment', 'sandbox');
    }
}
