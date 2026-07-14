<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Deliberately narrower than {@see PosIntegrationStatus}: there is no
 * `TokenExpired` case. A client_credentials grant has no refresh token, so an
 * expired access token is not a state needing merchant action — we just re-run
 * the grant with the stored credentials. Only credentials that Uber *rejects*
 * are a problem, and that is `Error`.
 */
#[TypeScript]
enum DeliveryIntegrationStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';

    public function isUsable(): bool
    {
        return $this === self::Connected;
    }
}
