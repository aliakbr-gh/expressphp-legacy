<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use DateTimeZone;
use Exception;
use ExpressPHP\Http\IPAddress;
use RuntimeException;

final class Config
{
    /**
     * Wraps the validated configuration array.
     */
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

    /**
     * Checks the settings from config/app.php and returns them with derived values filled in. Throws a
     * RuntimeException naming the setting when one is missing or unsafe, so startup stops instead of running
     * with a bad configuration.
     */
    public static function validate(array $config): array
    {
        // Debug output follows the single debug switch.
        $config['debug_dump']['enabled'] = $config['debug'];

        try {
            new DateTimeZone($config['timezone']);
        } catch (Exception) {
            throw new RuntimeException('config/app.php: timezone must be a valid PHP time zone identifier.');
        }

        if ($config['env'] === 'production') {
            if ($config['debug']) {
                throw new RuntimeException('config/app.php: debug must be false in production.');
            }
            if (!$config['force_https']) {
                throw new RuntimeException('config/app.php: force_https must be true in production.');
            }
        }

        // 64 characters is the hex form of 32 random bytes; shorter values do not carry 32 bytes of entropy.
        $config['jwt']['secret'] = trim((string)$config['jwt']['secret']);
        if (strlen($config['jwt']['secret']) < 64) {
            throw new RuntimeException('config/app.php: jwt.secret must be at least 64 characters, such as bin2hex(random_bytes(32)).');
        }

        foreach ($config['trusted_proxies'] as $proxy) {
            if (!is_string($proxy) || !IPAddress::validRange($proxy)) {
                throw new RuntimeException('config/app.php: trusted_proxies must contain IP addresses or CIDR ranges.');
            }
        }

        $origins = $config['cors']['origins'];
        if ($config['cors']['enabled'] && ($origins === [] || array_filter(
            $origins,
            static fn(string $origin): bool => in_array(trim($origin), ['', '*'], true),
        ) !== [])) {
            throw new RuntimeException('config/app.php: cors.origins must list explicit origins when CORS is enabled.');
        }

        $connection = $config['databases']['connections'][$config['databases']['default']] ?? null;
        if (!is_array($connection)) {
            throw new RuntimeException('config/app.php: the default database connection must be configured.');
        }
        $required = ['host', 'database', 'username'];
        if ($config['env'] === 'production') {
            $required[] = 'password';
        }
        foreach ($required as $key) {
            if (trim((string)($connection[$key] ?? '')) === '') {
                throw new RuntimeException('config/app.php: the default database requires ' . $key . '.');
            }
        }

        return $config;
    }

    /**
     * Returns the whole configuration array.
     */
    public function all(): array
    {
        return $this->values;
    }
}
