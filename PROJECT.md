# ExpressPHP project guide

ExpressPHP is a small PHP framework and REST API starter. It receives HTTP requests, checks their input and access rules, runs the requested action, and sends a response. It uses core PHP and PDO without Composer packages.

This guide is the full reference: it explains every maintained project file, and the [feature reference](#feature-reference) at the end covers validation, responses, views, uploads, email, backups, logs, and rate limits in detail. [README.md](README.md) is the short quick start.

This guide explains every maintained project file. Files created while the API runs are explained by their filename patterns under `storage/`, because their names and number change over time. All paths below start at the project root.

## The main folders

| Folder | What belongs here |
|---|---|
| `app/` | Your application's controllers, models, access checks, and PHP views. Application classes use the `App\` namespace. |
| `src/` | The reusable framework tools. These classes use the `ExpressPHP\` namespace. |
| `config/` | Settings you edit for this installation. |
| `routes/` | The API URLs and the actions they run. |
| `migrations/` | Instructions for creating or changing application database tables. |
| `cli/` | Commands you run from the terminal. |
| `public/` | The HTTP entry point and its Apache rules. |
| `storage/` | Private files created while the application runs. |
| `tests/` | Framework regression tests, run with `php tests/run.php`. |

A **controller** handles an API request. A **model** reads or saves application data. **Middleware** checks a request before the controller runs. A **route** connects an HTTP method and URL to a controller method.

## Files at the project root

| File | What it contains and does |
|---|---|
| `index.php` | The entry point when the whole project is the website's root. It passes execution to `public/index.php`. |
| `.htaccess` | Apache rules for requests entering the project root. Sends URLs to `index.php`, preserves authorization headers, disables directory browsing, and blocks direct access to source code, settings, private storage, tests, and project documents. |
| `.gitignore` | Keeps a stray `.env` file out of Git; the application never reads one. Runtime files are ignored by the `.gitignore` inside each `storage/` folder. |
| `README.md` | The quick start: setup, the login flow, building an endpoint, rate limits, commands, and the production checklist. |
| `PROJECT.md` | This file: the full reference to every file and feature. |
| `AGENTS.md` | Working rules for coding assistants and contributors, including architecture, PHP style, security, and verification. It does not run as part of the API. |
| `LICENSE` | The project's MIT license terms. It does not run as part of the API. |

If present, `.git/` contains Git's history and internal records. Git manages those files; they are separate from the application code.

## Configuration and entry points

| File | What it contains and does |
|---|---|
| `config/app.php` | Settings only: the application, debug output, logging, rate limits, backups, JWTs, passwords, email, uploads, databases, proxies, middleware, and CORS. It returns them through `Config::validate()`. Includes a commented example of a second database connection. |
| `public/index.php` | Starts the API: loads the bootstrap and settings, creates the application, registers routes, captures the request, and runs it. Returns a generic error if startup fails. |
| `public/.htaccess` | Apache rules used when `public/` is the website's root. Preserves authorization headers, blocks hidden files, and sends requests to its `index.php`. |
| `routes/api.php` | Defines every `/api/v1` endpoint and connects it to a controller method. Also assigns authentication and permission middleware to protected endpoints. |

`Config::validate()` in `src/Core/Config.php` checks the settings at startup and stops with a message naming the problem. It ties debug dumps to the main debug switch, rejects debug mode and plain HTTP in production, and checks the time zone, the JWT secret, trusted proxies, CORS origins, and the default database fields. It checks settings; it does not test a database connection.

Keep real credentials in `config/app.php` private and out of Git commits. Browser origins belong in `cors.origins`; the commented `reporting` connection becomes available only after you uncomment it and enter its settings.

## Application controllers

Controllers validate HTTP input, call a model or framework tool, and return a response. Their access rules are selected in `routes/api.php`.

| File | What it contains and does |
|---|---|
| `app/Controllers/ActivityLogController.php` | Lists activity records, shows one record, and accepts a new activity description. Supports a date filter and pagination. |
| `app/Controllers/AuthController.php` | Registers users, logs them in, and returns the current user. Login returns a signed JWT that the app sends until it receives 401. |
| `app/Controllers/DatabaseBackupController.php` | Creates a ZIP backup of the default database and sends it as a download. Records the action and removes the temporary SQL and ZIP files after sending them. |
| `app/Controllers/EmailController.php` | Sends email through a configured mailer and lists or shows delivery records. Records whether delivery succeeded or failed. |
| `app/Controllers/ExampleController.php` | Handles the public `GET /api/v1/example` endpoint and renders the example view with a title and message. Accepts no query or body fields and needs no authentication. |
| `app/Controllers/FileController.php` | Receives an uploaded `file`, sends it to the upload tool, and returns the stored file's details. Contains a commented example of custom upload restrictions. |
| `app/Controllers/HealthController.php` | Reports that the server is running and checks database availability with a small query. Returns an unavailable response if the database check fails. |
| `app/Controllers/PermissionController.php` | Lists, creates, edits, and deletes permissions. Also shows which roles have a permission and records changes as activities. |
| `app/Controllers/RateLimitController.php` | Lists blocked IP addresses, checks an IP's limiter status, manually blocks an IP, and clears its limiter record. Records manual changes as activities. |
| `app/Controllers/RoleController.php` | Manages roles and their permission assignments. Protects built-in roles and prevents deleting a role that users still have. |
| `app/Controllers/ServerLogController.php` | Returns request log entries for a selected date, or today when no date is supplied. Uses pagination to limit the returned entries. |
| `app/Controllers/UserController.php` | Lists, creates, edits, and deletes users; assigns roles; and shows permissions. Hashes passwords, prevents self-deletion, records changes, and invalidates old tokens when access-related user details change. |

## Application middleware

Middleware can stop a request before its controller runs. Authentication means checking who the caller is; authorization means checking what that caller may do.

| File | What it contains and does |
|---|---|
| `app/Middlewares/AuthMiddleware.php` | Verifies the bearer JWT, reloads its user, and rejects invalid or expired tokens and inactive or deleted users. Adds the verified user to the request. |
| `app/Middlewares/ThrottleMiddleware.php` | Limits requests per signed-in user (or per client for guests) through the `throttle:<max>,<seconds>` alias. Place it after `auth`. |
| `app/Middlewares/PermissionMiddleware.php` | Requires the signed-in user to have every permission named by the route. The `super-admin` role passes this check without individual permission grants. |
| `app/Middlewares/RoleMiddleware.php` | Allows only the role identifiers listed by the route, such as `admin`. Available through the `role` alias, although the current routes use authentication and permission checks. |

## Application models

Most models use a database table. `ServerLog.php` reads files instead.

| File | What it contains and does |
|---|---|
| `app/Models/ActivityLog.php` | Saves activity descriptions with the user's details, IP address, and user agent in `activity_logs`. Reads and counts records, optionally for a particular date. |
| `app/Models/EmailLog.php` | Defines the saved delivery fields in `email_logs`, such as recipient, subject, mailer, status, and sending time. Reads and counts delivery records with an optional date filter. |
| `app/Models/Permission.php` | Works with the `permissions` table, finds a permission by its slug, and lists the roles that have it. A slug is its short identifier, such as `users.view`. |
| `app/Models/Role.php` | Loads roles, their permissions, and their assigned-user counts. Adds, removes, or replaces permission assignments; replacing the list uses a database transaction. |
| `app/Models/ServerLog.php` | Reads dated request log files and returns the newest entries first. Supplies the file contents and total count needed for pagination. |
| `app/Models/User.php` | Queries users, checks passwords, loads roles and permissions, and removes password hashes from every returned row. Chooses the registration role. |

A user has one role, and a role has permissions such as `users.view`. The first registered user becomes `super-admin` when the users table is empty; later registrations receive the `user` role. Create that first account before allowing public access to a fresh installation.

Login returns a signed JWT that lasts 7 days (`jwt.ttl`). The server keeps no session state: the app logs out by deleting its token. Every request reloads the user, so deactivating or deleting a user refuses their token immediately, and the token's password fingerprint makes a password change sign out older tokens.

## Application views

| File | What it contains and does |
|---|---|
| `app/Views/example.php` | The HTML page used by `GET /api/v1/example`, showing the heading `Hello` and message `Rendered from your controller.` Displays the controller's title and message, with defaults, and escapes both values for HTML. |

Open [http://localhost/expressphp/api/v1/example](http://localhost/expressphp/api/v1/example) to see the example page.
It requires no authentication and accepts no query or body fields.

Add page templates under `app/Views/`. A template such as `app/Views/users/index.php` is selected with the name `users/index`.
Each supplied data key becomes a template variable, and `$data` contains the original array. Use the supplied `$escape()`
helper when displaying values in HTML text or quoted attributes. Templates are trusted PHP files; their data is not automatically escaped.

## Terminal commands

These files are PHP programs run from the terminal, such as `php cli/migrate status`. None of them has a `.php` extension.

| File | What it contains and does |
|---|---|
| `cli/make` | Starts the file generator. Creates a controller, model, middleware class, or timestamped migration from a template. Example: `php cli/make controller Product`. |
| `cli/migrate` | Starts the migration runner. Shows migration status, applies pending migrations, or reverses the latest batch; rollback only prints its plan unless `--force` is given. Accepts an optional connection name after the command. |
| `cli/rate-limit` | Manages the same IP limiter records used by the API. Supports `status`, `block`, `clear`, `blocked`, and `prune` (deletes unused counters). Example: `php cli/rate-limit status 192.0.2.10`. |
| `cli/check-deploy` | Requests private paths such as `/.git/HEAD`, `/config/app.php`, today's log file, and `/tests/run.php` from a deployed site and fails if any is reachable. Example: `php cli/check-deploy https://api.example.com`. |

## Tests

| File | What it contains and does |
|---|---|
| `tests/run.php` | Framework regression tests with no dependencies. Covers request input, routing, responses, logging, validation limits, JWT, passwords, models (on a temporary SQLite file), uploads, mail, and the generator. Exits with code 1 if any test fails. |

## Application database migration

| File | What it contains and does |
|---|---|
| `migrations/2026_08_25_000001_init_project.php` | Creates `roles`, `permissions`, `role_permissions`, `users`, `activity_logs`, and `email_logs`. Inserts the standard roles and permissions and grants all permissions to the administrative roles. Its `down()` method drops these tables in the required order. |

The migration creates no default user or password. The migration runner separately maintains a `migrations` table to remember which files have run. A rollback can remove tables and their data, so use it only when you intend to reverse those changes.

Keep application migrations at the project root. The reusable code that runs them belongs in `src/Database/Migrations/`.

## Framework startup and core

| File | What it contains and does |
|---|---|
| `src/bootstrap.php` | Registers the class loader for `App\` and `ExpressPHP\`, records when execution started, and loads the helper functions. This lets PHP find class files without Composer. |
| `src/helpers.php` | Global helper functions: `dd()` dumps values and stops the request; `utf8_scrub()` repairs invalid UTF-8 and `utf8_truncate()` shortens text without splitting a character, for storing client-supplied text such as the User-Agent in MySQL. |
| `src/Core/Config.php` | Checks the settings at startup (`validate()`) and gives read-only access to them by dot path. The router passes it to controller and middleware constructor parameters typed `Config`. |
| `src/Core/ErrorHandler.php` | Turns off PHP error display, converts warnings into exceptions, and records fatal errors in the private error log. |
| `src/Core/Application.php` | Connects the configured framework tools and provides route registration methods. Handles requests through CORS, rate limiting, and routing; adds security headers; turns failures into responses; and writes request logs after sending the response. |
| `src/Core/Debugger.php` | Builds the developer dump page with values, request information, and runtime details. Redacts known sensitive fields and shows detailed output only when debug dumps are enabled. |
| `src/Core/MakeCommand.php` | Contains the templates and writing logic behind `cli/make`. Builds filenames and namespaces and refuses to overwrite an existing file. |

## Framework authentication

A JWT is a signed access token. The signature lets the server check that a token has not been changed; authentication middleware also checks the user's current database state.

| File | What it contains and does |
|---|---|
| `src/Auth/JWT.php` | Creates HS256 JWTs with an issue time and expiry, and returns a token's claims only when its signature is valid and it has not expired. |
| `src/Auth/JWTException.php` | Defines the error type for token creation and verification failures. It lets other code recognize a token-related failure. |
| `src/Auth/Password.php` | Hashes passwords with bcrypt, checks them, spends equal time on missing accounts, and reports hashes with another cost or algorithm so login can replace them. |

## Framework database tools

PDO is PHP's database connection tool. Application models use it through these shared classes.

| File | What it contains and does |
|---|---|
| `src/Database/Database.php` | Keeps connection settings, opens a named PDO connection when needed, and reuses it. Sets a connection timeout and a UTC MySQL session, and provides `transaction()`. Supports MySQL and SQLite connection strings; the starter's migration and backup tools use MySQL. |
| `src/Database/Model.php` | Provides shared methods for reading, creating, editing, deleting, counting, and paginating rows. Binds values by type, removes `$hidden` columns from results, and returns `$dates` columns as ISO 8601 UTC. Child models choose a table, an optional connection, and the fields that may be saved. |
| `src/Database/DatabaseBackup.php` | Writes MySQL table definitions and rows to an SQL file, then puts that file in a ZIP archive. Uses generated filenames in the private backup directory. |
| `src/Database/Migrations/Migration.php` | Defines the two methods every migration provides: `up()` applies a change, and `down()` reverses it. |
| `src/Database/Migrations/Migrator.php` | Finds migration files, runs pending ones in filename order under a database lock, and records completed migrations. Shows their status without writing, and lists or reverses the latest batch; the CLI requires `--force` to roll back. |

The migration runner creates its tracking table when it first migrates or rolls back; `status` only reads. The second database example can be selected with `ExpressPHP\Database\Database::connection('reporting')` after enabling its settings.

## Framework HTTP tools

| File | What it contains and does |
|---|---|
| `src/Http/Request.php` | Wraps incoming JSON, form fields, query values, headers, cookies, uploaded files, and route parameters. Provides validation helpers, trusted-proxy handling, and a place to store the authenticated user. |
| `src/Http/Response.php` | Builds and sends responses with status codes and headers. Supports success JSON, error JSON with a string `message` and an `errors` object, text, HTML, PHP views with data, cookies, redirects, streams, and downloads. |
| `src/Http/CORS.php` | Adds browser cross-origin response headers when the requesting origin is allowed. Uses the configured methods, headers, and credential policy. |
| `src/Http/IPAddress.php` | Normalizes IPv4 and IPv6 addresses and matches them against IPs or CIDR ranges for trusted proxies. |
| `src/Http/Pagination.php` | Wraps a result list with its total, limit, offset, returned count, and information about the next page. |
| `src/Http/HttpException.php` | Carries an error message and HTTP status code so the application can return the appropriate response. |
| `src/Http/Router.php` | Registers routes and groups, finds the matching route, runs its middleware, and calls its controller or callback. Returns 405 for a known path with the wrong method and 404 when no route matches. |
| `src/Http/Route.php` | Stores one route's method, URL pattern, action, and middleware. Matches a URL and extracts placeholders such as `{id}`. |
| `src/Http/RateLimiter.php` | Counts requests in private fixed-window files: per client (IPv4 address or IPv6 /64) before routing, and per any key such as a signed-in user. Manages manual IP blocks and deletes unused counters. |
| `src/Http/RateLimitDecision.php` | Holds a limiter result: whether a request is allowed, whether the IP is blocked, remaining requests, reset time, and retry delay. Builds the 429 or 403 response and the `X-RateLimit-*` headers. |
| `src/Http/View.php` | Finds PHP templates inside the views folder and captures their rendered HTML. Supplies template variables and an HTML escape helper, rejects invalid paths and reserved variable names, and discards buffered output if rendering fails. |

## Framework validation

| File | What it contains and does |
|---|---|
| `src/Validation/Validator.php` | Checks values against rules for types, bounds, formats, conditional requirements, date comparisons, and database existence or uniqueness. Supports wildcard array-item rules, rejects unknown fields in declared schemas, and returns normalized values. |
| `src/Validation/ValidationException.php` | Holds validation errors keyed by field, with request-level errors under `_request`. The application returns them as HTTP 422 with the message `Invalid Data`. |

## Framework logging, mail, and uploads

| File | What it contains and does |
|---|---|
| `src/Logging/RequestLogger.php` | Writes request details to a daily log, including response status, duration, IP address, and user information. Redacts sensitive fields, never logs the raw URL, limits logged input, and returns log pages newest first. |
| `src/Logging/ErrorLogger.php` | Records unexpected exceptions with their request ID in `storage/logs/errors-Y-m-d.log`. |
| `src/Logging/Redactor.php` | Decides which keys are sensitive and replaces their values; shared by the request log and the debugger. |
| `src/Logging/LogFiles.php` | Reads log files backwards in fixed-size chunks, counts lines without loading the file, and deletes dated logs past the retention period. |
| `src/Mail/SMTPMailer.php` | Sends HTML or plain-text email directly through a configured SMTP server. Handles server replies, authentication, encryption, and message formatting without an external package. |
| `src/Mail/MailException.php` | Defines the error type for email configuration and delivery failures. |
| `src/Storage/FileUploader.php` | Checks uploaded files, size, extension, and detected content type before saving them under generated filenames. Supports multiple uploads and limits deletion to files inside the upload directory. |
| `src/Storage/FileUploadException.php` | Defines the error type for upload validation and storage failures. |

Exception files contain small error classes. Their job is to label a kind of failure so the calling code can catch and handle it appropriately.

## Private storage

These files contain runtime data rather than application instructions. Apache blocks direct web access to `storage/`. Database backups use a protected download endpoint. Uploaded files stay private; the current API has no download route for them.

| File or pattern | What it contains and does |
|---|---|
| `storage/*/.gitignore` | One in each of `backups/`, `logs/`, `rate-limiter/`, and `uploads/`. Keeps the folder in Git and everything created in it out of Git. |
| `storage/logs/YYYY-MM-DD.log` | Daily request log entries written by `RequestLogger` and read by `ServerLog`. These are different from user activity records stored in the database. |
| `storage/logs/errors-YYYY-MM-DD.log` | Unexpected exceptions and fatal errors written by `ErrorLogger`, with the request ID returned to the client. |
| `storage/rate-limiter/counters/*.json` | Request counters used by `RateLimiter`, one file per client, user, or login username. Unused files are deleted after `record_ttl_seconds`. |
| `storage/rate-limiter/blocked/*.json` | IP addresses blocked manually through the API or `php cli/rate-limit block`. |
| `storage/uploads/*` | Uploaded files stored under generated names after validation. The saved file type depends on the allowed upload policy. |
| `storage/backups/*.sql` | Temporary database exports, deleted as soon as the ZIP is written. |
| `storage/backups/*.zip` | Temporary download archives containing those SQL exports. The backup controller removes temporary files after sending them. |

## How a request moves through the files

1. Apache applies `.htaccess` and sends the request to `index.php`, which loads `public/index.php`. If the website root is `public/`, it enters `public/index.php` directly.
2. `public/index.php` loads `src/bootstrap.php`, reads `config/app.php`, creates `Application`, and registers `routes/api.php`.
3. `Request` collects the incoming method, URL, headers, and input. `Application` applies request handling, including CORS preflight and rate limiting.
4. `Router` finds the matching route and runs its middleware. A rejected request stops here.
5. The controller validates the input and carries out the action. It calls a model when it needs application data, or a framework tool for work such as email or uploads.
6. `Response` sends the result with the headers supplied by `Application`. `RequestLogger` then writes the request log.

For example, `GET /api/v1/users` goes through `AuthMiddleware`, then `PermissionMiddleware`, then `UserController`. The controller asks `User` for the data and returns it as JSON.

## Where to make changes

| Change you want | Files to start with |
|---|---|
| Change settings | `config/app.php` |
| Add or change an API URL | `routes/api.php` and its controller in `app/Controllers/` |
| Add a database query | The relevant model in `app/Models/` |
| Add an HTML page | A template in `app/Views/`, its controller, and its route |
| Change access rules | The route's middleware list and, when needed, a class in `app/Middlewares/` |
| Add or change a table | A new file under `migrations/` |
| Add a terminal command | A file under `cli/` |
| Improve a reusable framework feature | The relevant class under `src/` |
| Understand installation or API usage | `README.md` |

Application-specific features belong in `app/`; shared framework behavior belongs in `src/`. Keep this file updated when you add, rename, or remove project files.

## Feature reference

### Requests and responses

```php
return $response->success($data, 'Request successful');
return $response->success($data, 'Resource created', 201);
return $response->error('Resource not found', 404);
return $response->error('Invalid Data', 422, ['email' => ['The email field is required.']]);
```

- Every error response is `{"success": false, "message": string, "errors": {field: [messages]}, "data": null}`.
  `errors` is `{}` when there are no field errors. Validation failures use `Invalid Data`; errors about the request as
  a whole appear under `_request`, and at most 50 fields are reported.
- `validate()` reads the query string for `GET`, `HEAD`, and `DELETE`, and the body for `POST`, `PUT`, and `PATCH`,
  never both. Route parameters use `validateParams()`. Unknown fields are rejected, and non-multipart bodies larger than
  `request.max_body_bytes` return 413.
- Every response carries a server-generated `X-Request-ID` that matches the request and error logs; a client's own
  `X-Request-ID` is logged as `client_request_id`.
- A known path with the wrong method returns 405 with an `Allow` header, and `HEAD` runs the `GET` route without a body.
- Times are stored and returned in UTC as ISO 8601 (`2026-10-13T08:15:00Z`); `timezone` only names log and backup files.
- Models remove `$hidden` columns from every row and format `$dates` columns. `Database::transaction(fn () => ...)`
  makes related writes atomic. A constructor parameter typed `ExpressPHP\Core\Config` receives the configuration.
- `$response->redirect('/dashboard')` accepts only paths on this site; another host needs `allowExternal: true`.

### Validation rules

| Purpose | Rules |
|---|---|
| Presence | `required`, `optional`, `sometimes`, `nullable` |
| Conditional presence | `required_if:field,value`, `required_unless:field,value`, `required_with:field`, `required_without:field` |
| Types | `string` (valid UTF-8), `integer`, `numeric`, `boolean`, `array`, `object`, `list` |
| Formats | `email`, `ip`, `url`, `uuid`, `regex:pattern`, `date`, `date_format:format` |
| Bounds and choices | `min:value`, `max:value`, `between:min,max`, `in:value,...`, `not_in:value,...` |
| Matching and duplicates | `same:field`, `different:field`, `confirmed`, `distinct` |
| Date comparisons | `before`, `before_or_equal`, `after`, `after_or_equal` (another field or a fixed date) |
| Database checks | `unique:table,column,connection,ignoreId,idColumn`, `exists:table,column,connection` |

- Put the type rule before bounds, such as `integer|min:1`. Bounds compare numbers, count array items, and measure
  strings in bytes, which keeps the 72-byte bcrypt password limit.
- `object` is a JSON object and `list` a sequential JSON array; nested fields use dot notation, such as `profile.timezone`.
- `*` validates every array item, for example `items.*.quantity => 'required|integer|min:1'`. One wildcard may hold at
  most 1,000 items, and items under an invalid parent are not validated.
- Required rules, including triggered conditional rules, take precedence over `optional`. `nullable` allows an explicit
  `null` when the field is not required.
- To ignore the current record in an update's uniqueness check, pass its trusted ID:
  `'unique:users,username,,' . $user['id'] . ',id'`.
- Custom messages accept exact or wildcard paths, such as `items.*.quantity.min`, and the placeholders `:attribute`,
  `:index`, and `:position`.

### Views

```php
return $response->view('example', ['title' => 'Hello', 'message' => 'Rendered from your controller.']);
```

Templates live in `app/Views/` and are selected by relative name, such as `users/index`. Each data key becomes a
variable, plus `$data` and an `$escape()` helper; use `<?= $escape($title) ?>` for anything displayed. A PHP warning,
such as an undefined variable, fails the render with 500, so give optional values a default (`$cars ?? []`). View
responses send `Content-Security-Policy: default-src 'self'`, which blocks inline scripts and styles.

### File uploads

```php
$uploaded = $this->uploads->upload($request->file('file'), 'documents');
$path = $this->uploads->path($clientSuppliedPath);          // null when outside storage/uploads
return $response->sendFile($path, basename($path));          // sandboxed attachment
```

Uploads need an extension allowlist and a MIME policy in `config/app.php`; executable and markup extensions such as
`php`, `html`, and `svg` are always rejected. Files are stored under generated names in the private
`storage/uploads/`. `uploadMany()` removes already stored files if one fails. `sendFile()` takes the content type from
the extension, never from the file contents.

### Email

Configure SMTP or Gmail in the `mail` section of `config/app.php` (Gmail needs an app password and `encryption` =
`tls`), then send with `(new SMTPMailer())->send(SMTPMailer::defaultMailer(), $to, $subject, $body)`. STARTTLS requires TLS 1.2 or newer, and authentication without encryption
is refused. The example email endpoints store delivery records in `email_logs` without bodies or credentials.

### Database backups

`GET /api/v1/database-backups/download` (permission `database-backups.download`) dumps the default database from one
consistent snapshot, streams rows, skips generated columns, and sends a ZIP. Temporary files are deleted even if the
client disconnects. For large databases, prefer `mysqldump --single-transaction` from cron.

### Logs

Request logs are written to `storage/logs/Y-m-d.log` and exceptions to `storage/logs/errors-Y-m-d.log`.
Sensitive keys (passwords, tokens, secrets, cookies, `otp`, `pin`) are redacted, the raw URL is never logged,
exception traces never record argument values, and files older than `logging.retention_days` are deleted. Log files
are created with mode `0640`. PHP errors are never displayed in responses. `statement_timeout_ms` on a MySQL
connection stops any single statement after that many milliseconds.

### Rate limits

The global limit counts each client (an IPv4 address or IPv6 /64) before routing; `throttle:<max>,<seconds>` adds a
per-user limit on a route. Going over returns 429 with `Retry-After` and `data.retry_after`, plus `X-RateLimit-*`
headers on every response. A manually blocked IP gets 403 until cleared; blocking an IPv6 address blocks its /64.
About one request in `prune_chance` deletes up to `prune_batch` counters unused for `record_ttl_seconds`;
`php cli/rate-limit prune` cleans everything, and `prune_chance` = `0` leaves pruning to cron. If the counters cannot
be saved, requests are allowed except on the `fail_closed` paths. Counters are stored per server.

