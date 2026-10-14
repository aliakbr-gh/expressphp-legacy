<?php

declare(strict_types=1);

namespace ExpressPHP\RateLimit;

use ExpressPHP\Http\Response;

final class RateLimitDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $blocked,
        public readonly int  $limit,
        public readonly int  $remaining,
        public readonly int  $resetAt,
        public readonly int  $retryAfter = 0,
    ) {
    }

    /**
     * Builds the refusal: 403 for a manually blocked address, otherwise 429 with Retry-After.
     */
    public function toResponse(Response $response): Response
    {
        if ($this->blocked) {
            return $this->withHeaders($response->error('This IP address has been blocked', 403));
        }

        return $this->withHeaders($response
            ->error('Too many requests', 429, data: ['retry_after' => $this->retryAfter])
            ->header('Retry-After', (string)$this->retryAfter));
    }

    public function withHeaders(Response $response): Response
    {
        return $response
            ->header('X-RateLimit-Limit', (string)$this->limit)
            ->header('X-RateLimit-Remaining', (string)$this->remaining)
            ->header('X-RateLimit-Reset', (string)$this->resetAt);
    }
}
