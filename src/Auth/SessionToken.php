<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

/**
 * Opaque bearer tokens for database-backed sessions. The client keeps the token; the database keeps
 * only its SHA-256 hash, so a leaked sessions table cannot be used to sign in.
 */
final class SessionToken
{
    /**
     * Returns a new random token: 32 bytes written as 64 hexadecimal characters.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Returns the SHA-256 hash that is stored in place of the token.
     */
    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Rejects values that cannot be a token before any database lookup.
     */
    public static function wellFormed(#[\SensitiveParameter] string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }
}
