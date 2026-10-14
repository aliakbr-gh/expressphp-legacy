<?php

declare(strict_types=1);

namespace ExpressPHP\Support;

/**
 * UTF-8 helpers for storing client-supplied text, such as headers, in utf8mb4 columns. Strict MySQL rejects
 * invalid UTF-8, so values must be repaired and cut on a character boundary before they are stored.
 */
final class UTF8
{
    /**
     * Replaces invalid UTF-8 byte sequences with U+FFFD and returns valid values unchanged.
     */
    public static function scrub(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        // The JSON encoder substitutes invalid sequences; it is always available, unlike mbstring.
        return (string)json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), false, 1, JSON_THROW_ON_ERROR);
    }

    /**
     * Repairs the value and shortens it to at most $maxBytes bytes without splitting a character.
     */
    public static function truncate(string $value, int $maxBytes): string
    {
        $value = self::scrub($value);
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $value = substr($value, 0, max(0, $maxBytes));
        // A UTF-8 character is at most 4 bytes, so at most 3 trailing bytes belong to a cut character.
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }
}
