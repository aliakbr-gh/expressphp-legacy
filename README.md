# ExpressPHP

A zero-dependency PHP micro-framework and starter kit for REST APIs with MySQL, built for mobile app backends. It uses
core PHP and PDO only: no Composer, no `vendor/`.

[PROJECT.md](PROJECT.md) is the full reference: every file, every feature, and how a request moves through the code.

## Features

- Routing, middleware, and versioned API routes
- JWT login with bcrypt password hashing
- Roles and permissions
- Validation with one stable JSON error shape
- PDO models and migrations
- Per-client and per-user rate limits, plus manual IP blocking
- Request and error logs, health checks, SMTP email, safe file uploads, CORS

## Requirements

- PHP 8.2 or newer, with PDO MySQL, OpenSSL, JSON, and Fileinfo
- MySQL 8 or MariaDB
- Apache with `mod_rewrite`

## Setup

1. Create a database and edit `config/app.php`. It holds settings only; `Config::validate()` stops startup with a
   clear message if one is missing or unsafe.
   - `databases.connections.mysql`: host, port, database, username, password.
   - `jwt.secret`: a unique value per environment, generated once:
     ```bash
     php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
     ```
2. Create the tables, roles, and permissions:
   ```bash
   php cli/migrate migrate
   ```
3. Register the first account with `POST /api/v1/auth/register`. The first user becomes `super-admin`; later users get
   `user`. Do this before making a new installation public.

With MAMP and the project in `htdocs/expressphp`, the API is at `http://localhost/expressphp/api/v1/...`, for example
`/api/v1/health/server`.

## Authentication

```text
POST /api/v1/auth/register   {"name", "username", "password"}
POST /api/v1/auth/login      {"username", "password"}
GET  /api/v1/auth/me
```

Login returns a JWT that lasts `jwt.ttl` seconds (7 days):

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { "id": 1, "username": "owner", "role_slug": "super-admin", "permissions": [] },
    "token": "eyJhbGciOiJIUzI1NiIs...",
    "token_type": "Bearer",
    "expires_at": "2026-10-21T12:00:00Z"
  }
}
```

The app stores the token and sends `Authorization: Bearer <token>` with every protected request. On **401** the token
is missing, invalid, or expired: delete it and show the login screen. The server keeps no sessions, so logging out
means deleting the token. Deactivating or deleting a user, or changing their password, makes their existing tokens fail
immediately. On **429**, wait `Retry-After` seconds; do not log out.

## Building an endpoint

Generate the files:

```bash
php cli/make migration create_products_table
php cli/make model Product
php cli/make controller Product
```

Validate input, then answer with `success()` or `error()`:

```php
public function store(Request $request, Response $response): Response
{
    $data = $request->validate([
        'name' => 'required|string|min:2|max:100',
        'price' => 'required|numeric|min:0',
    ]);

    return $response->success($this->products->create($data), 'Product created', 201);
}
```

Register the route with authentication, a per-user limit, and the narrowest permission:

```php
$router->post('/products', [ProductController::class, 'store'], [
    'auth',
    'throttle:120,60',
    'permission:products.create',
]);
```

Add new permissions to the migration's seed list. Unknown request fields are rejected. Every error response has the
same shape:

```json
{
  "success": false,
  "message": "Invalid Data",
  "errors": { "name": ["The name field is required."] },
  "data": null
}
```

`message` is always a string and `errors` is always an object (`{}` when empty). Collections use `limit` and `offset`,
and date filters use `date=Y-m-d`. Times are returned in UTC as ISO 8601.

## Rate limits

- **Every request:** `rate_limiter.max_requests` per `window_seconds` (600 per minute) for each IPv4 address or IPv6 /64.
- **Per signed-in user:** the `throttle:<max>,<seconds>` route middleware, placed after `auth`.

Going over a limit returns 429 with `Retry-After` until the window resets. A manually blocked IP gets 403 until
cleared.

## Commands

```bash
php cli/migrate status | migrate | rollback --force   # rollback without --force only prints its plan
php cli/make controller|model|middleware|migration <Name>
php cli/rate-limit status|block|clear <ip>
php cli/rate-limit blocked | prune
php cli/check-deploy https://api.example.com          # confirms private files are not served
php tests/run.php                                     # framework tests; no database needed
```

## Production checklist

- `env` = `'production'`, `debug` = `false`, `force_https` = `true`
- A new `jwt.secret` for each environment
- Point the document root at `public/`. If the host only allows the whole project as the root, run
  `php cli/check-deploy https://your-domain` after every deployment.
- Exact `cors.origins` for any web client, and `trusted_proxies` when behind a proxy or CDN
- A restricted database account; `config/` and `storage/` readable only by the owner and PHP
- Apache `ServerTokens Prod` and `ServerSignature Off`
- PHP request and upload limits sized for your app

## License

MIT; see [LICENSE](LICENSE).
