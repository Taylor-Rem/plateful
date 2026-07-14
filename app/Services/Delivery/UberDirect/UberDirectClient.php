<?php

namespace App\Services\Delivery\UberDirect;

use App\Services\Pos\Square\SquareClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Owns the Uber Direct HTTP surface: the host, the pinned API version, and the
 * shared timeout.
 *
 * Deliberately has no environment/host switch, unlike {@see SquareClient}.
 * Uber Direct serves test and production from the SAME host — test mode is a
 * property of the credentials, toggled in the Uber dashboard. Verified against
 * the live sandbox: there is no sandbox-api host to select.
 */
class UberDirectClient
{
    public const HOST = 'https://api.uber.com';

    /**
     * API version pinned for every request. Bump deliberately after reading
     * Uber's changelog — never float it.
     */
    public const API_VERSION = 'v1';

    public function authed(string $accessToken): PendingRequest
    {
        return Http::baseUrl(self::HOST)
            ->acceptJson()
            ->withToken($accessToken)
            ->timeout(15);
    }

    /**
     * Every Direct endpoint is scoped under the restaurant's own customer id.
     */
    public function customerPath(string $customerId, string $suffix = ''): string
    {
        return '/'.self::API_VERSION.'/customers/'.$customerId.$suffix;
    }
}
