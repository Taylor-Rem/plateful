<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An image handed to the operator API could not be used: unreachable URL,
 * bad base64, unsupported format, or too large. Renders as a 422.
 */
class InvalidImageException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
