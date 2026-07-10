<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum PosIntegrationStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case TokenExpired = 'token_expired';
    case Error = 'error';

    public function isUsable(): bool
    {
        return $this === self::Connected;
    }
}
