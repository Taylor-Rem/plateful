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

    /**
     * The provider rejected the restaurant's credentials. `$detail` is surfaced
     * to the owner on the integration screen, so it must read as an
     * instruction, not a stack trace.
     */
    public static function authenticationFailed(string $provider, string $detail): self
    {
        return new self("Authentication with provider [{$provider}] failed. {$detail}");
    }
}
