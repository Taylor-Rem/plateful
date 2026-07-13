<?php

namespace App\Exceptions;

use RuntimeException;

class PosProviderException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("POS provider [{$provider}] is not configured for this restaurant.");
    }

    public static function driverNotAvailable(string $provider): self
    {
        return new self("No adapter registered for POS provider [{$provider}].");
    }

    public static function pushFailed(string $reason): self
    {
        return new self("POS push failed: {$reason}");
    }

    public static function oauthFailed(string $reason): self
    {
        return new self("POS OAuth failed: {$reason}");
    }
}
