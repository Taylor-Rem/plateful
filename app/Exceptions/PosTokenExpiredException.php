<?php

namespace App\Exceptions;

use App\Enums\PosProviderName;

class PosTokenExpiredException extends PosProviderException
{
    public static function for(PosProviderName $provider): self
    {
        return new self("POS provider [{$provider->value}] access token has expired; re-authentication required.");
    }
}
