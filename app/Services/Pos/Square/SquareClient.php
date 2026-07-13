<?php

namespace App\Services\Pos\Square;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Owns the Square HTTP surface: the environment-selected host, the pinned API
 * version, and the shared timeout. Both the OAuth handshake and order pushing
 * build their requests from here so the host/version live in one place.
 */
class SquareClient
{
    /**
     * Square API version pinned for every request. Bump deliberately after
     * reading the Square changelog — never float it.
     */
    public const API_VERSION = '2025-06-18';

    /**
     * An unauthenticated request — used for the OAuth token/revoke endpoints,
     * which authenticate with the application secret in the body or a header.
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

    public function host(): string
    {
        return $this->environment() === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl($this->host())
            ->acceptJson()
            ->withHeaders(['Square-Version' => self::API_VERSION])
            ->timeout(15);
    }

    private function environment(): string
    {
        return (string) config('services.square.environment', 'sandbox');
    }
}
