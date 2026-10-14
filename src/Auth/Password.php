<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

use ExpressPHP\Validation\ValidationException;
use RuntimeException;

/**
 * Password hashing with Argon2id (about 40-60 ms per hash at the default settings), falling back to bcrypt on
 * PHP builds without Argon2 support. Hashes made with other settings or with bcrypt still verify, and
 * needsRehash() reports them so login can upgrade them.
 */
final class Password
{
    // Hashes of a random, discarded password with the default Argon2id and bcrypt settings. verifyMissing() checks
    // against one, so a login for a missing account takes as long as one for a real account.
    private const TIMING_HASH = '$argon2id$v=19$m=19456,t=2,p=1$WS55L0tzTXRyOHAwMzhQUA$BJBG1TFj8iZhwsyX24MQxkJRCMLO2MYj0Bm4LdHwqO8';
    private const BCRYPT_TIMING_HASH = '$2y$10$HB8wlaC8rRj7xsKkGtDKEuvLOt2ktO9LILuYh2ufvNiGURSNJ7DH2';

    private static array $options = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
    private static int $bcryptCost = 10;

    /**
     * Sets the Argon2id memory (KiB), time and thread settings, and the bcrypt cost used when Argon2 is missing.
     */
    public static function configure(array $config): void
    {
        $options = [
            'memory_cost' => (int)($config['memory_cost'] ?? 19456),
            'time_cost' => (int)($config['time_cost'] ?? 2),
            'threads' => (int)($config['threads'] ?? 1),
        ];
        $bcryptCost = (int)($config['bcrypt_cost'] ?? 10);
        if ($options['memory_cost'] < 8192 || $options['time_cost'] < 1 || $options['threads'] < 1) {
            throw new RuntimeException('Password settings need memory_cost >= 8192 KiB, time_cost >= 1 and threads >= 1.');
        }
        if ($bcryptCost < 4 || $bcryptCost > 31) {
            throw new RuntimeException('Password bcrypt cost must be between 4 and 31.');
        }

        self::$options = $options;
        self::$bcryptCost = $bcryptCost;
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

        return password_hash($password, self::algorithm(), self::options());
    }

    /**
     * Checks a password against a stored Argon2id or bcrypt hash. A password containing a NUL character never matches.
     */
    public static function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return !str_contains($password, "\0") && password_verify($password, $hash);
    }

    /**
     * Spends about the same time as verify() and always returns false. Call it when the account does not exist,
     * so response time does not reveal which usernames are registered.
     */
    public static function verifyMissing(#[\SensitiveParameter] string $password): bool
    {
        $hash = self::algorithm() === PASSWORD_BCRYPT ? self::BCRYPT_TIMING_HASH : self::TIMING_HASH;
        // With non-default settings the stored hash would verify faster or slower than real hashes.
        if (self::needsRehash($hash)) {
            $hash = password_hash(bin2hex(random_bytes(16)), self::algorithm(), self::options());
        }
        password_verify($password, $hash);
        return false;
    }

    /**
     * Reports whether a stored hash uses another algorithm or other settings and should be replaced after login.
     */
    public static function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    /**
     * Returns Argon2id when this PHP build supports it, otherwise bcrypt.
     */
    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /**
     * Returns the hashing options for the selected algorithm.
     */
    private static function options(): array
    {
        return self::algorithm() === PASSWORD_BCRYPT ? ['cost' => self::$bcryptCost] : self::$options;
    }
}
