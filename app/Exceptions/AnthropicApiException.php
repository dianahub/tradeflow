<?php

namespace App\Exceptions;

use RuntimeException;

class AnthropicApiException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 503)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
