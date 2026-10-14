<?php

declare(strict_types=1);

namespace ExpressPHP\Logging;

final class Redactor
{
    public const REDACTED = '[redacted]';

    // Matched anywhere in a normalized key, so new_password and refresh_token are covered.
    private const SENSITIVE_PARTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'cookie',
        'apikey',
        'privatekey',
        'credential',
    ];

    // Short names are matched exactly to avoid redacting keys such as "shipping".
    private const SENSITIVE_KEYS = ['otp', 'pin', 'cvv', 'cvc', 'ssn'];

    /**
     * Reports whether a key name marks a secret.
     */
    public static function sensitive(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_', ' '], '', $key));
        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }
        foreach (self::SENSITIVE_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replaces secret values in nested arrays and truncates long strings.
     */
    public static function redact(mixed $value, int $maxStringBytes = 1000): mixed
    {
        if (is_string($value)) {
            return strlen($value) > $maxStringBytes ? substr($value, 0, $maxStringBytes) . '[truncated]' : $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sensitive((string)$key) ? self::REDACTED : self::redact($item, $maxStringBytes);
        }
        return $value;
    }
}
