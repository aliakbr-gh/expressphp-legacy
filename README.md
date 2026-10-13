# ExpressPHP

ExpressPHP is a zero-dependency PHP micro-framework and starter kit for building secure, production-ready REST APIs with
MySQL.

It uses core PHP and PDO. There is no Composer or `vendor/` directory.

See [PROJECT.md](PROJECT.md) for a simple guide to every file and how requests move through the project.

## Features

- Versioned routing and middleware
- JWT authentication and token invalidation
- Roles and permissions
- Validation and pagination
- PDO models and migrations
- Rate limiting and permanent IP blocking
- Activity logs and daily request logs
- Server and database health checks
- SMTP and Gmail email
- Secure file uploads
- CORS and trusted proxies

## Requirements

- PHP 8.2 or newer
- MySQL 8 or MariaDB
- Apache with `mod_rewrite`
- PDO MySQL, OpenSSL, JSON, and Fileinfo PHP extensions

## Installation

Create a database and edit the values directly in `config/app.php`:

- Set `databases.connections.mysql` to your database host, database name, username, and password.
- Set `timezone`, `env`, and `debug` for the environment.
- Set `jwt.secret` to a unique random secret generated once for this environment:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Paste the result into `jwt.secret`; keep it stable between requests and use a separate secret for each environment.

Then run:

```bash
php cli/migrate.php migrate
```

The migration creates the standard roles and permissions. There is no default username or password.
Register your first account through `POST /api/v1/auth/register` before making a fresh installation public. The first
registered user becomes `super-admin`; later users receive the `user` role. Registration requires `name`, `username`, and
`password` (at least 8 characters). Existing accounts keep their roles.

With MAMP and this project under `htdocs/expressphp`:

```text
http://localhost/expressphp/api/v1/health/server
http://localhost/expressphp/api/v1/auth/login
```

In production, point the web server's document root at `public/`. If the host only lets you upload the whole project as
the document root (for example `public_html` for `digi.100xsoftware.pk`), the root `.htaccess` blocks private files, but
only while Apache honors it. Check every deployment with `php cli/check-deploy https://your-domain`:

```text
https://digi.100xsoftware.pk/api/v1/health/server
https://digi.100xsoftware.pk/api/v1/auth/login
```

On the production host, set `'env' => 'production'`, `'debug' => false`, `'force_https' => true`, a unique `jwt.secret`, and
`'origins' => ['https://digi.100xsoftware.pk']` in the `cors` array. Native mobile apps can omit browser CORS; the origin list
is for any web client. Keep deployed credentials out of Git.

## Structure

```text
app/
├── Controllers/
├── Middlewares/
├── Models/
└── Views/
src/
├── Auth/
├── Core/
├── Database/
├── Http/
├── Logging/
├── Mail/
├── RateLimit/
├── Routing/
├── Storage/
├── Validation/
├── View/
└── bootstrap.php
config/
└── app.php
cli/
public/
migrations/
routes/
storage/
```

Application classes use the `App\` namespace. Reusable framework classes use `ExpressPHP\`.

Keep `migrations/` at the project root. It describes application database changes, alongside `app/`, `routes/`, and `config/`.
The reusable migration runner belongs in `src/Database/Migrations/`.

## Creating an API

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Product;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class ProductController
{
    public function __construct(
        private readonly Product $products = new Product(),
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $response->success($this->products->all(), 'Products loaded');
    }
}
```

```php
$router->get(
    '/products',
    [ProductController::class, 'index'],
    ['auth', 'permission:products.view'],
);
```

Generate starter files:

```bash
php cli/make controller Product
php cli/make model Product
php cli/make middleware ProductAccess
php cli/make migration create_products_table
```

## Responses and validation

```php
return $response->success($data, 'Request successful');
return $response->success($data, 'Resource created', 201);
return $response->error('Resource not found', 404);
```

Render a PHP template with controller data:

```php
return $response->view('example', [
    'title' => 'Hello',
    'message' => 'Rendered from your controller.',
]);
```

`ExampleController::index()` uses this response at `GET /api/v1/example`. Open
[http://localhost/expressphp/api/v1/example](http://localhost/expressphp/api/v1/example) to see the heading `Hello` and
the message `Rendered from your controller.` This public example requires no authentication and accepts no query or body fields.

This renders `app/Views/example.php`. Use relative names such as `example` or `users/index`, optionally ending in `.php`.
View paths must stay inside `app/Views/`. The renderer captures the HTML and returns a normal response, so status codes,
CORS, security headers, and request logging still apply.

Templates receive each data key as a variable, plus the original `$data` array and an `$escape()` helper:

```php
<h1><?= $escape($title) ?></h1>
```

Use `$escape()` for values displayed in HTML text or quoted attributes. Templates contain trusted PHP code; passing data
does not automatically escape it. The names `data`, `escape`, PHP superglobals, and names starting with `__` are reserved.

```php
$data = $request->validate([
    'name' => 'required|string|min:2|max:100',
    'email' => 'required|string|email',
    'is_active' => 'optional|boolean',
    'profile' => 'optional|object',
    'profile.timezone' => 'optional|string',
    'tags' => 'optional|list',
]);
```

Use `array` for any PHP array, `object` for a JSON object, and `list` for a sequential
JSON array. Nested object fields use dot notation, such as `profile.timezone`.

Supported validation rules:

| Purpose | Rules |
|---|---|
| Presence | `required`, `optional`, `sometimes`, `nullable` |
| Conditional presence | `required_if:field,value`, `required_unless:field,value`, `required_with:field`, `required_without:field` |
| Types | `string`, `integer`, `numeric`, `boolean`, `array`, `object`, `list` |
| Formats | `email`, `ip`, `url`, `uuid`, `regex:pattern`, `date`, `date_format:format` |
| Bounds and choices | `min:value`, `max:value`, `between:min,max`, `in:value,...`, `not_in:value,...` |
| Matching and duplicates | `same:field`, `different:field`, `confirmed`, `distinct` |
| Date comparisons | `before:field-or-date`, `before_or_equal:field-or-date`, `after:field-or-date`, `after_or_equal:field-or-date` |
| Database checks | `unique:table,column,connection,ignoreId,idColumn`, `exists:table,column,connection` |

Missing fields are omitted unless a required rule applies. Required rules, including triggered conditional rules, take
precedence over `optional` and `sometimes`. `nullable` allows an explicit `null` when the field is not required.
For `required_with`, a nonempty referenced field triggers the requirement; for `required_without`, a missing or empty
referenced field triggers it. Both accept multiple field names. Boolean conditions using `true` or `false` recognize the
same values as the `boolean` rule.

`integer` rejects values outside PHP's integer range. `numeric` supports finite numbers, including scientific notation.
Bounds compare numeric values after normalization, count array entries, and measure string length in bytes, preserving
the 72-byte bcrypt password limit. Put the type rule before bounds, such as `integer|min:1`.
`date` requires a real calendar date and rejects relative expressions; use `date_format:Y-m-d` for an exact API date format.
Date comparisons accept another field or a fixed date. `same`, `different`, and `confirmed` compare the original submitted
values strictly; declare a confirmation field in your rules when using `confirmed`.

Use `*` to validate every array item:

```php
$data = $request->validate([
    'items' => 'required|list|min:1',
    'items.*' => 'required|object',
    'items.*.sku' => ['required', 'string', 'regex:/^[A-Z]{2,4}-[0-9]+$/', 'distinct'],
    'items.*.quantity' => 'required|integer|min:1',
]);
```

Declare the parent list as required when at least one item is needed. `distinct` rejects duplicate values across a wildcard
field's items after any preceding type normalization. Wildcard references such as `same:items.*.expected` refer to the
corresponding item. An array or list with no child rules allows arbitrary contents; adding child rules enforces its schema
and rejects unknown children, even when the parent also has a rule.

Regex rules support pipes and commas inside their patterns. Rule arrays are useful for keeping complex definitions readable.
Custom messages can use exact field paths or wildcard paths, such as `items.*.quantity.min`, and placeholders such as
`:attribute`, `:index` (zero-based), and `:position` (one-based).

For conditional fields and date ranges:

```php
$data = $request->validate([
    'delivery' => 'required|in:email,pickup',
    'email' => 'optional|required_if:delivery,email|email',
    'start' => 'required|date_format:Y-m-d',
    'end' => 'required|date_format:Y-m-d|after_or_equal:start',
]);
```

The database connection parameter is optional. To exclude the current record from an update's uniqueness check, leave
the connection parameter empty for the default connection and supply the loaded record's ID:

```php
$rules['username'] = 'required|string|unique:users,username,,' . $user['id'] . ',id';
```

Use an ID from a trusted record loaded by the controller; do not let the request choose which record to exclude.
An omitted ID column defaults to `id`. Uploaded-file size, extension, and detected MIME checks remain in `FileUploader`.

`validate()` reads the query string for `GET`, `HEAD`, and `DELETE`, and the body for `POST`, `PUT`, and `PATCH`; it never
mixes them. A `POST`, `PUT`, or `PATCH` with query parameters, or a `GET` with a body, is rejected, so secrets cannot be
accepted from URLs. Route parameters use `validateParams()`. Non-multipart bodies larger than `request.max_body_bytes`
return 413. One wildcard may hold at most 1,000 items, and items under an invalid parent are not validated.

Unknown fields are rejected. Invalid request values return HTTP 422. Malformed or unsupported rule definitions are
programming errors and fail before input validation.

Every response carries `X-Request-ID`, which matches the request and error logs. A known path with the wrong method
returns 405 with an `Allow` header; `HEAD` runs the `GET` route without a body. Times are stored and returned in UTC as
ISO 8601 (`2026-10-13T08:15:00Z`); `timezone` only names daily log files and backup files.

Models remove the columns listed in `$hidden` from every returned row and format `$dates` columns as ISO 8601. Use
`Database::transaction(fn () => ...)` to make related writes atomic. A controller or middleware constructor parameter
typed `ExpressPHP\Core\Config` receives the application configuration:

```php
public function __construct(private readonly Config $config) {}
// $this->config->get('rate_limiter.enabled')
```

Collections use `limit` and `offset`:

```text
GET /api/v1/users?limit=20&offset=0
```

## Authentication and RBAC

Access tokens last `jwt.ttl` seconds (default 86400). The IP limiter defaults to 120 requests per 60 seconds. Tune the
`rate_limiter` array in `config/app.php` for a chatty mobile client.

```text
POST /api/v1/auth/register
POST /api/v1/auth/login
GET  /api/v1/auth/me
POST /api/v1/auth/refresh
POST /api/v1/auth/logout
```

Send protected requests with:

```http
Authorization: Bearer <token>
```

Refresh creates a replacement token and invalidates previous tokens for that user. Logout invalidates all active tokens
through `session_version`.

Tokens carry a `kid`. To rotate the signing key without logging everyone out, move the current `kid` and `secret` into
`jwt.previous_keys`, set a new `kid` and `secret`, and remove the old entry after `jwt.ttl` seconds.

```php
['auth', 'permission:reports.view']
['auth', 'role:admin,manager']
```

## Logs and health

```text
GET /api/v1/health/server
GET /api/v1/health/database
GET /api/v1/activity-logs?date=2026-08-25&limit=20&offset=0
GET /api/v1/server-logs?date=2026-08-25&limit=20&offset=0
GET /api/v1/rate-limits/blocked?limit=20&offset=0
GET /api/v1/rate-limits/status?ip=192.0.2.10
POST /api/v1/rate-limits/block
POST /api/v1/rate-limits/clear
```

Block and clear accept `{"ip":"192.0.2.10"}`. These match the `php cli/rate-limit` commands and require `rate-limits.view`,
`rate-limits.block`, or `rate-limits.clear`.

Daily JSON request logs are stored under `storage/logs/Y-m-d.log`, and unexpected exceptions under
`storage/logs/errors-Y-m-d.log`. Keys containing words such as password, token, secret, or cookie, and keys such as
`otp` and `pin`, are redacted; the raw URL is not logged. Files older than `logging.retention_days` are deleted.
PHP errors are never displayed in responses.

## Database backups

```text
GET /api/v1/database-backups/download
```

This route takes no query parameters. It dumps the current database, zips it, and downloads a file named with the
application timezone in 12-hour AM/PM form, for example `expressphp_db-2026-09-22-06-58-PM.zip`. The dump uses one
consistent snapshot, streams rows without loading tables into memory, and skips generated columns. The plaintext SQL file
is deleted before the download starts, and the ZIP is deleted afterwards, even if the client disconnects. The permission
is `database-backups.download`. For large databases, prefer `mysqldump --single-transaction` from cron.

## Email

Configure generic SMTP or Gmail in the `mail` array in `config/app.php`. For Gmail:

```php
'mail' => [
    'enabled' => true,
    'default' => 'gmail',
    'from_address' => 'your-account@gmail.com',
    'from_name' => 'ExpressPHP',
    'timeout' => 10,
    'mailers' => [
        'gmail' => [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'your-account@gmail.com',
            'password' => '', // Your Gmail app password.
        ],
    ],
],
```

```text
POST /api/v1/emails/send
GET  /api/v1/emails?date=2026-08-25&limit=20&offset=0
GET  /api/v1/emails/{id}
```

Email bodies and credentials are never stored in `email_logs`.

## File uploads

```php
use ExpressPHP\Storage\FileUploader;

public function __construct(
    private readonly FileUploader $uploads = new FileUploader(),
) {
}

$uploaded = $this->uploads->upload($request->file('file'), 'documents');
```

Global restrictions live in `config/app.php`. Files are stored under the private `storage/uploads/` directory with
`file_mode` and `directory_mode` permissions. Uploads fail when no extension allowlist or MIME policy is configured;
executable and markup extensions such as `php`, `html`, and `svg` are always rejected. Per-upload `allowed_mime_types`
replaces the global per-extension map. `uploadMany()` removes already stored files if any file fails.

Serve stored files with `$response->sendFile($path, $downloadName)`. The type comes from the extension, never from the
file contents, and the response is a sandboxed attachment unless `inline: true` is passed.

## Commands

```bash
php cli/migrate.php status
php cli/migrate.php migrate
php cli/migrate.php rollback          # prints the plan; production also requires --force

php cli/check-deploy https://api.example.com

php cli/rate-limit status 192.0.2.10
php cli/rate-limit block 192.0.2.10
php cli/rate-limit clear 192.0.2.10
php cli/rate-limit blocked
```

## Production checklist

- Set `env` to `'production'` and `debug` to `false`; debug dumps follow this switch
- Keep a unique `jwt.secret` generated from at least 32 random bytes (also required locally)
- Point the document root at `public/`, or verify the root `.htaccess` with `php cli/check-deploy`
- Use a restricted database account
- Set `force_https` to `true` (required in production)
- Configure exact origins in `cors.origins` (never `*`) and `trusted_proxies` as IPs or CIDR ranges
- Enable HTTPS
- Keep `config/`, `storage/`, and deployed secrets private and out of public downloads
- Restrict `config/app.php` permissions to the owner and the PHP service account
- Restrict `database-backups.download` to trusted administrators
- Configure PHP request and upload limits
- Configure SMTP sender authentication
- Monitor health checks and logs

## License

ExpressPHP is open-source software licensed under the [MIT License](LICENSE).
