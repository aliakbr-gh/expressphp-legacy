<?php

declare(strict_types=1);

// Settings only; Config::validate() checks them and stops startup with a clear message when one is unsafe.
return ExpressPHP\Core\Config::validate([
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
        // About one request in this many prunes up to prune_batch unused counters; 0 leaves pruning to
        // `php cli/rate-limit prune`, for example from cron.
        'prune_chance' => 100,
        'prune_batch' => 2000,
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

    // Login tokens (HS256 JWT). Generate the secret with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // Changing the secret signs everyone out.
    'jwt' => [
        'secret' => '6dfa86541dd7fb8558426c9a0d4583337ac251dc3ec1f4bf3c8963ef4dc4a90c',
        // Tokens last this many seconds (7 days); the app logs out when it receives 401.
        'ttl' => 604800,
    ],

    // bcrypt password hashing; a few kilobytes of memory per hash. Each +1 doubles the time (10 is about
    // 50-150 ms on a typical server). Hashes made with another cost are replaced at the user's next login.
    'password' => [
        'bcrypt_cost' => 10,
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
]);
