<?php

namespace App\Exceptions;

use RuntimeException;

class AiRateLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterMs,
    ) {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): int
    {
        return max((int) ceil($this->retryAfterMs / 1000), 1);
    }
}
