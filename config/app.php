<?php

declare(strict_types=1);

$config = [
    // Application identity, timezone, and environment.
    'name' => 'ExpressPHP',
    'timezone' => 'Asia/Karachi',
    'env' => 'development',
    'debug' => true,
    'base_path' => '',

    // Reject plain-HTTP requests with 403. Must be true in production; set trusted_proxies when TLS ends at a proxy.
    'force_https' => false,

    // Largest non-multipart request body in bytes. Multipart uploads are bounded by PHP's post_max_size.
    'request' => [
        'max_body_bytes' => 1048576,
    ],

    // Developer dumps; enabled follows the debug switch.
    'debug_dump' => [
        'status' => 500,
        'max_depth' => 6,
        'show_headers' => true,
    ],

    // Daily request logs and errors-Y-m-d.log error logs, stored privately and deleted after retention_days.
    'logging' => [
        'enabled' => true,
        'path' => dirname(__DIR__) . '/storage/logs',
        'max_input_bytes' => 4096,
        'retention_days' => 30,
    ],

    // Request limits. The global limit counts each client: one IPv4 address or one IPv6 /64 network.
    // It is generous because mobile carriers put many phones behind one IPv4 address; per-user limits
    // come from the 'throttle:<max>,<seconds>' route middleware placed after 'auth'.
    'rate_limiter' => [
        'enabled' => true,
        'max_requests' => 600,
        'window_seconds' => 60,
        'ipv6_prefix' => 64,
        // Failed logins allowed per account and client network before a 429, and per account from all
        // networks together. Unknown usernames are counted per client network with the first limit.
        'login_max_attempts' => 5,
        'login_window_seconds' => 900,
        'login_account_max_attempts' => 50,
        'login_account_window_seconds' => 3600,
        // Unused counters are deleted after this long; manual IP blocks are kept until cleared.
        'record_ttl_seconds' => 86400,
        'path' => dirname(__DIR__) . '/storage/rate-limiter',
        'except' => [
            '/api/v1/health/server',
        ],
        'fail_closed' => [
            '/api/v1/auth/login',
            '/api/v1/auth/register',
        ],
    ],

    // Temporary files for downloadable database backups.
    'backups' => [
        'path' => dirname(__DIR__) . '/storage/backups',
    ],

    // Authentication tokens; generate the secret with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // To rotate, move the current kid and secret into previous_keys, then set a new kid and secret.
    'jwt' => [
        'secret' => '6dfa86541dd7fb8558426c9a0d4583337ac251dc3ec1f4bf3c8963ef4dc4a90c',
        'kid' => 'primary',
        'previous_keys' => [],
        'issuer' => 'expressphp',
        'audience' => 'expressphp-api',
        'ttl' => 86400,
        'leeway' => 5,
    ],

    // Login sessions: one per user (a new login signs out the previous device). Each request that uses the
    // token pushes its expiry back to session_idle_seconds from now, but never past session_lifetime_seconds
    // after login. The expiry is written at most once per session_touch_seconds.
    'auth' => [
        'session_idle_seconds' => 604800,      // 7 days without use
        'session_lifetime_seconds' => 7776000, // 90 days after login, however active
        'session_touch_seconds' => 3600,
    ],

    // Bcrypt password hashing.
    'password' => [
        'bcrypt_cost' => 12,
    ],

    // Outgoing email through named SMTP profiles.
    'mail' => [
        'enabled' => false,
        'default' => 'smtp',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'ExpressPHP',
        'timeout' => 10,
        // EHLO and Message-ID domain; empty uses the from_address domain.
        'ehlo_domain' => '',
        'mailers' => [
            'smtp' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
            ],
            'gmail' => [
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'your-account@gmail.com',
                'password' => '',
            ],
        ],
    ],

    // Private uploads checked by size, extension, and detected MIME type.
    'uploads' => [
        'path' => dirname(__DIR__) . '/storage/uploads',
        'max_size' => 10485760,
        'file_mode' => 0640,
        'directory_mode' => 0750,
        'allowed_extensions' => [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'pdf',
            'txt',
            'csv',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'zip',
        ],
        'mime_types_by_extension' => [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'doc' => ['application/msword'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
        ],
    ],

    // Named PDO connections; mysql is the default.
    'databases' => [
        'default' => 'mysql',
        'connections' => [
            'mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3305,
                'database' => 'expressphp_db',
                'username' => 'root',
                'password' => 'root',
                'charset' => 'utf8mb4',
                // Seconds to wait for a connection.
                'timeout' => 5,
                // Longest single statement in milliseconds; 0 disables. Backups lift it for their own session.
                'statement_timeout_ms' => 0,
            ],

            // Uncomment this profile and set its credentials to enable a second database.
            // Select it with ExpressPHP\Database\Database::connection('reporting').
            // 'reporting' => [
            //     'driver' => 'mysql',
            //     'host' => '127.0.0.1',
            //     'port' => 3306,
            //     'database' => 'reporting_db',
            //     'username' => 'reporting_user',
            //     'password' => '',
            //     'charset' => 'utf8mb4',
            // ],
        ],
    ],

    // Proxy IPs or CIDR ranges (for example '10.0.0.0/8') allowed to supply forwarding headers.
    'trusted_proxies' => [],

    // Middleware aliases used by routes.
    'middleware' => [
        'auth' => App\Middlewares\AuthMiddleware::class,
        'role' => App\Middlewares\RoleMiddleware::class,
        'permission' => App\Middlewares\PermissionMiddleware::class,
        'throttle' => App\Middlewares\ThrottleMiddleware::class,
    ],

    // Browser access from explicitly allowed origins.
    'cors' => [
        'enabled' => true,
        'origins' => ['http://localhost'],
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With'],
        'expose_headers' => [
            'Content-Disposition',
            'X-Request-ID',
            'Retry-After',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ],
        'credentials' => false,
        'max_age' => 86400,
    ],
];

// Checks run inside a closure so that requiring this file defines no variables in the caller's scope;
// CLI scripts and the front controller only receive the returned array.
return (static function (array $config): array {
    // Debug output follows the single debug switch above.
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
    $secrets = [$config['jwt']['secret'], ...array_values($config['jwt']['previous_keys'])];
    foreach ($secrets as $secret) {
        $secret = trim((string)$secret);
        if (strlen($secret) < 64 || in_array($secret, [
            'expressphp-development-secret-change-this-before-production-2026',
            'change-this-to-a-random-secret-with-at-least-32-bytes',
        ], true)) {
            throw new RuntimeException('config/app.php: jwt secrets must be at least 64 characters, such as bin2hex(random_bytes(32)).');
        }
    }
    $config['jwt']['secret'] = trim($config['jwt']['secret']);

    $idle = $config['auth']['session_idle_seconds'];
    $lifetime = $config['auth']['session_lifetime_seconds'];
    if (!is_int($idle) || !is_int($lifetime) || !is_int($config['auth']['session_touch_seconds'])
        || $idle < 60 || $lifetime < $idle || $config['auth']['session_touch_seconds'] < 0) {
        throw new RuntimeException('config/app.php: auth session times must be integers with 60 <= idle <= lifetime.');
    }

    foreach ($config['trusted_proxies'] as $proxy) {
        if (!is_string($proxy) || !ExpressPHP\Http\IPAddress::validRange($proxy)) {
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
})($config);
