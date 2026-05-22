<?php

namespace App\Exceptions;

use RuntimeException;

class DeliveryProviderException extends RuntimeException
{
    public static function driverNotAvailable(string $provider): self
    {
        return new self("No driver available from provider [{$provider}].");
    }

    public static function notConfigured(string $provider): self
    {
        return new self("Provider [{$provider}] is not configured for this restaurant.");
    }
}
