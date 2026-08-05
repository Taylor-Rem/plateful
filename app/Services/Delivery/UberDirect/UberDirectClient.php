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
 * the live sandbox: there is no sandbox-api host to select. The base URL is
 * config-driven only so tests can point it elsewhere.
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
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($accessToken)
            ->timeout(15);
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('platform.delivery.uber.base_url', self::HOST), '/');
    }

    /**
     * Every Direct delivery endpoint is scoped under a customer id — under the
     * umbrella model, the restaurant's provisioned sub-organization id.
     */
    public function customerPath(string $customerId, string $suffix = ''): string
    {
        return '/'.self::API_VERSION.'/customers/'.$customerId.$suffix;
    }

    /**
     * The Organizations API root, used to provision restaurant sub-orgs.
     */
    public function organizationsPath(string $suffix = ''): string
    {
        return '/'.self::API_VERSION.'/direct/organizations'.$suffix;
    }
}
