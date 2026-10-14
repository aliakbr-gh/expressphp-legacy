<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

use ExpressPHP\Validation\ValidationException;
use RuntimeException;

/**
 * bcrypt password hashing. It needs only a few kilobytes of memory per hash; the cost sets the time
 * (cost 10 is roughly 50-150 ms on a typical server). Hashes made with another cost or algorithm still verify,
 * and needsRehash() reports them so login can replace them.
 */
final class Password
{
    // bcrypt hash (cost 10) of a random, discarded password. verifyMissing() checks against it, so a login for a
    // missing account takes as long as one for a real account.
    private const TIMING_HASH = '$2y$10$HB8wlaC8rRj7xsKkGtDKEuvLOt2ktO9LILuYh2ufvNiGURSNJ7DH2';

    private static int $cost = 10;

    /**
     * Sets the bcrypt cost, which must be between 4 and 31.
     */
    public static function configure(array $config): void
    {
        $cost = (int)($config['bcrypt_cost'] ?? 10);
        if ($cost < 4 || $cost > 31) {
            throw new RuntimeException('Password bcrypt cost must be between 4 and 31.');
        }
        self::$cost = $cost;
    }

    /**
     * Hashes a password. Passwords containing a NUL character are rejected as invalid input (HTTP 422),
     * because bcrypt cannot hash them.
     */
    public static function hash(#[\SensitiveParameter] string $password): string
    {
        if (str_contains($password, "\0")) {
            throw new ValidationException(['password' => ['The password must not contain null characters.']]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::$cost]);
    }

    /**
     * Checks a password against a stored hash. A password containing a NUL character never matches.
     */
    public static function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return !str_contains($password, "\0") && password_verify($password, $hash);
    }

    /**
     * Spends the same time as verify() and always returns false. Call it when the account does not exist,
     * so response time does not reveal which usernames are registered.
     */
    public static function verifyMissing(#[\SensitiveParameter] string $password): bool
    {
        $hash = self::needsRehash(self::TIMING_HASH)
            ? password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => self::$cost])
            : self::TIMING_HASH;
        password_verify($password, $hash);
        return false;
    }

    /**
     * Reports whether a stored hash uses another cost or algorithm and should be replaced after login.
     */
    public static function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::$cost]);
    }
}
