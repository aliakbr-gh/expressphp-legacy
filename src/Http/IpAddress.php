<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

final class IpAddress
{
    /**
     * Returns the canonical text form of an IPv4 or IPv6 address, or null when it is invalid.
     * Accepts bracketed IPv6, an IPv4 port suffix, and IPv4-mapped IPv6 addresses.
     */
    public static function normalize(string $address): ?string
    {
        $address = trim($address);
        if (preg_match('/\A\[([^\]]+)\](?::\d+)?\z/', $address, $match) === 1) {
            $address = $match[1];
        } elseif (preg_match('/\A(\d{1,3}(?:\.\d{1,3}){3}):\d+\z/', $address, $match) === 1) {
            $address = $match[1];
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $normalized = inet_ntop($packed);
        return $normalized === false ? null : $normalized;
    }

    public static function validRange(string $range): bool
    {
        return self::parseRange($range) !== null;
    }

    public static function inRange(string $address, string $range): bool
    {
        $parsed = self::parseRange($range);
        $normalized = self::normalize($address);
        if ($parsed === null || $normalized === null) {
            return false;
        }

        [$network, $bits] = $parsed;
        $packed = (string)inet_pton($normalized);
        if (strlen($packed) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;
        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    public static function inAny(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (is_string($range) && self::inRange($address, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function parseRange(string $range): ?array
    {
        $parts = explode('/', trim($range), 2);
        $address = self::normalize($parts[0]);
        if ($address === null) {
            return null;
        }

        $packed = (string)inet_pton($address);
        $maximum = strlen($packed) * 8;
        if (!isset($parts[1])) {
            return [$packed, $maximum];
        }
        if (preg_match('/\A\d{1,3}\z/', $parts[1]) !== 1 || (int)$parts[1] > $maximum) {
            return null;
        }

        return [$packed, (int)$parts[1]];
    }
}
