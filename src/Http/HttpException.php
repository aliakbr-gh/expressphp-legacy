<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /**
     * Creates an exception that the application returns as an error response with this status code.
     */
    public function __construct(
        string               $message,
        private readonly int $statusCode = 400,
    ) {
        parent::__construct($message);
    }

    /**
     * Returns the HTTP status code.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
