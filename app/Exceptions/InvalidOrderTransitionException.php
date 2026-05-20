<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InvalidOrderTransitionException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct("Cannot transition order from {$from->value} to {$to->value}.");
    }

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
