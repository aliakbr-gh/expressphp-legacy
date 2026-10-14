<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

use ExpressPHP\Validation\ValidationException;
use RuntimeException;

final class Password
{
    private static int $bcryptCost = 12;

    /**
     * Sets the bcrypt cost, which must be between 4 and 31.
     */
    public static function configure(array $config): void
    {
        $cost = (int)($config['bcrypt_cost'] ?? 12);
        if ($cost < 4 || $cost > 31) {
            throw new RuntimeException('Password bcrypt cost must be between 4 and 31.');
        }

        self::$bcryptCost = $cost;
    }

    /**
     * Bcrypt cannot hash NUL characters, so such passwords are rejected as invalid input (HTTP 422).
     */
    public static function hash(#[\SensitiveParameter] string $password): string
    {
        if (str_contains($password, "\0")) {
            throw new ValidationException(['password' => ['The password must not contain null characters.']]);
        }

        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => self::$bcryptCost,
        ]);
    }

    /**
     * Checks a password against a stored hash. A password containing a NUL character never matches.
     */
    public static function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return !str_contains($password, "\0") && password_verify($password, $hash);
    }

    /**
     * Reports whether a stored hash used a different cost and should be replaced.
     */
    public static function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, [
            'cost' => self::$bcryptCost,
        ]);
    }
}
