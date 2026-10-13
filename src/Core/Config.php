<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    /**
     * Reads a value by dot path, such as "rate_limiter.enabled".
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function all(): array
    {
        return $this->values;
    }
}
