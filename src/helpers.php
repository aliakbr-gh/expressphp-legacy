<?php

declare(strict_types=1);

use ExpressPHP\Core\Debugger;

if (!function_exists('dd')) {
    /**
     * Dumps the values and stops the request. When debugging is off, it returns a generic 500 instead.
     */
    function dd(mixed ...$values): never
    {
        Debugger::dump(...$values);
    }
}

if (!function_exists('utf8_scrub')) {
    /**
     * Replaces invalid UTF-8 byte sequences with U+FFFD and returns valid text unchanged. Use it before storing
     * client-supplied text, such as headers, because strict MySQL refuses invalid UTF-8.
     */
    function utf8_scrub(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        // The JSON encoder substitutes invalid sequences; it is always available, unlike mbstring.
        return (string)json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), false, 1, JSON_THROW_ON_ERROR);
    }
}

if (!function_exists('utf8_truncate')) {
    /**
     * Repairs the text with utf8_scrub() and shortens it to at most $maxBytes bytes without splitting a character,
     * so it fits a column such as VARCHAR(500).
     */
    function utf8_truncate(string $value, int $maxBytes): string
    {
        $value = utf8_scrub($value);
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
