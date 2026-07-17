<?php

namespace App\Services\Delivery\DoorDash;

use App\Services\Delivery\UberDirect\UberDirectClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Owns the DoorDash Drive HTTP surface: the host, the pinned Drive API version,
 * and the shared timeout.
 *
 * Like {@see UberDirectClient} — and unlike the Square/Clover clients — there is
 * no environment/host switch: DoorDash serves sandbox and production from the
 * same host (openapi.doordash.com), and test mode is a property of the
 * credentials. The base URL is nonetheless config-driven so tests can point it
 * elsewhere and a future host move has one place to change.
 *
 * `authed()` mints a fresh DD-JWT-V1 per call ({@see DoorDashJwtService}); there
 * is no stored bearer token to attach the way the Uber client requires one to be
 * passed in.
 */
class DoorDashClient
{
    /**
     * Drive API version pinned for every request. Bump deliberately after
     * reading DoorDash's changelog — never float it.
     */
    public const API_VERSION = 'v2';

    public function __construct(private DoorDashJwtService $jwt) {}

    public function authed(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($this->jwt->mint())
            ->timeout(15);
    }

    /**
     * A path under the versioned Drive namespace, e.g. `/drive/v2/quotes`.
     */
    public function drivePath(string $suffix = ''): string
    {
        return '/drive/'.self::API_VERSION.$suffix;
    }

    /**
     * A path under the Developer namespace, e.g. `/developer/v1/businesses`.
     * This is the provisioning surface (creating Businesses/Stores), separate
     * from the Drive delivery surface above.
     */
    public function developerPath(string $suffix = ''): string
    {
        return '/developer/v1'.$suffix;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('platform.delivery.doordash.base_url', 'https://openapi.doordash.com'), '/');
    }
}
