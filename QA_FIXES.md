# ExpressPHP QA fixes

Fixes for the framework-core findings in [CLAUDE_QA_REVIEW.md](CLAUDE_QA_REVIEW.md). Applied 2026-10-13 on top of
commit `4b2ccbd`.

**Fixed:** FW-01, 02, 04, 06, 08, 09, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25.

**Not in scope for this round:**

- FW-03: rate-limiter keying, cleanup and storage backend.
- FW-05: refresh-token sessions.
- FW-07: error `message` shape.

Their review entries remain open.

## Breaking changes for API clients and application code

| Change | Who is affected | Finding |
|---|---|---|
| `POST`, `PUT` and `PATCH` reject query parameters. `GET` requests with a body are rejected. | Clients that send fields in the URL on write requests | FW-01 |
| Non-multipart bodies over `request.max_body_bytes` (1 MB) return 413 | Clients sending large JSON | FW-11 |
| A wrong method on a known path returns 405 instead of 404 | Clients that check for 404 | FW-12 |
| Model timestamps are returned as `2026-10-13T08:15:00Z` instead of `2026-10-13 18:15:00`, and are always UTC | Clients that parse dates | FW-14 |
| `Model` results no longer include `$hidden` columns. Code that needs them, such as password checks, uses `rawFirstWhere()`. | Custom models | FW-18 |
| `Route::match()` decodes `+` as `+`, not as a space | Routes with string parameters | FW-12 |
| Uploads fail if `allowed_extensions` or a MIME policy is missing, and always fail for `php`, `html`, `svg` and similar extensions | Custom upload configs | FW-10 |
| `jwt.secret` must be at least 64 characters | Configs with short secrets | FW-17 |
| `force_https` must be `true` when `env` is `production` | Production configs | FW-15 |
| `DatabaseBackup::createZIP()` no longer returns `sql_path`; the SQL file is already deleted | Custom backup callers | FW-20 |
| `Response::stream()` clears earlier `Content-Length` and `Content-Disposition` headers. Set them after calling `stream()`. | Custom streaming code | FW-08 |
| `Response::headers()` no longer contains `Set-Cookie`; use `cookies()` | Code reading cookie headers | FW-13 |
| Migration rollback in production requires `--force` | Deploy scripts | FW-21 |
| Existing tokens without a `kid` header are still accepted, so no re-login is needed | None | FW-17 |

## Fixes by finding

### FW-01 — Query string merged into body validation; secrets logged in the URL

- `src/Http/Request.php`: `validate()` reads the query only for `GET`, `HEAD` and `DELETE`, and the body only for
  `POST`, `PUT` and `PATCH`. Query keys on a write request, or a body on a read request, raise a 422 that names the
  field.
- `src/Logging/RequestLogger.php`: the `url` field was removed. The log keeps `path` plus a redacted `query`.
- `src/Core/Debugger.php`: the dump shows the path and request id, not the raw URL.

### FW-02 — Client-chosen IP behind a trusted proxy

- `src/Http/IpAddress.php` (new): normalizes IPv4 and IPv6 (brackets, ports, IPv4-mapped IPv6) and matches CIDR
  ranges.
- `src/Http/Request.php`:
  - `ip()` walks `X-Forwarded-For` from the right and returns the first address that isn't a trusted proxy.
  - `protocol()` uses the right-most `X-Forwarded-Proto` value and accepts only `http` or `https`.
  - Trusted proxies can be IPs or CIDR ranges.
- `config/app.php`: `trusted_proxies` accepts CIDR ranges, and each entry is checked at startup.

### FW-04 — Exceptions never logged; PHP error display uncontrolled

- `src/Core/ErrorHandler.php` (new):
  - turns off `display_errors` and enables `log_errors`;
  - converts warnings and notices into `ErrorException`, still respecting `@`;
  - logs deprecations without throwing;
  - records fatal errors in a shutdown function and returns a JSON 500.
- `src/Logging/ErrorLogger.php` (new): writes `storage/logs/errors-Y-m-d.log` with the request id, method, path, user
  id, exception, previous exception and trace.
- `public/index.php`: registers the handler and logs startup failures.
- `src/Core/Application.php`:
  - logs unexpected `Throwable`s and non-constraint `PDOException`s;
  - logs stream failures after `emit()`;
  - sends `X-Request-ID` on every response.
- `config/app.php`: `X-Request-ID` was added to `cors.expose_headers`.

### FW-06 — `.htaccess` is the only file boundary

- `.htaccess`: blocks every `*.md` file (including the QA reports) and points to `public/` as the preferred document
  root.
- `cli/check-deploy` (new): requests 16 private paths from a deployed URL and exits 1 if any returns anything other
  than 401, 403, 404 or 405.
- `README.md`: recommends a `public/` document root and running `check-deploy` after each deploy.

### FW-08 — A stream survives being replaced by an error

- `src/Http/Response.php`: every method that sets a body goes through `setBody()`. That method clears the stream
  callback and the `Content-Length`, `Content-Disposition` and `Content-Security-Policy` headers.

### FW-09 — `sendFile()` serves files inline using a sniffed type

- `src/Http/Response.php`: `sendFile($path, $downloadName, $contentType, $inline = false)` takes its type from an
  extension map, with `application/octet-stream` as the fallback. It never sniffs contents. It defaults to
  `attachment` and adds `Content-Security-Policy: default-src 'none'; sandbox`.

### FW-10 — Uploader fails open; per-upload MIME types ignored

- `src/Storage/FileUploader.php`:
  - **Fail closed:** uploads are rejected when no extension allowlist or MIME policy exists.
  - **Always rejected:** files without an extension, and executable or markup extensions (`php*`, `phtml`, `phar`,
    `html`, `svg`, `js` and others), even if allowlisted.
  - **Per-call MIME list:** `allowed_mime_types` passed for one upload now replaces the global map.
  - **Office files:** a `docx` or `xlsx` reported as a plain ZIP must contain `[Content_Types].xml` and the matching
    main document.
  - **Batches:** `uploadMany()` deletes files already stored when a later file fails.
  - **Permissions:** files use `file_mode` (default `0640`) and directories use `directory_mode` (default `0750`).
- `config/app.php`: added `uploads.file_mode` and `uploads.directory_mode`.

### FW-11 — No body limit; JSON decoded twice; quadratic path lookup

- `src/Http/Request.php`:
  - `capture()` refuses bodies larger than `max_body_bytes`, using `Content-Length` first and then a capped read.
    Multipart is excluded because PHP's `post_max_size` bounds it.
  - JSON is decoded once, as objects, and converted to arrays while object paths are recorded as hash keys.
- `src/Core/Application.php`: returns 413 when the body is too large.
- `src/Validation/Validator.php`: object and list checks look up paths with `isset`.
- `config/app.php`: added `request.max_body_bytes` (1 MB).

### FW-12 — Router contract gaps

- `src/Routing/Router.php`:
  - middleware with an empty parameter (for example `permission:` or `permission:a,,b`) fails at registration;
  - registering the same method and path twice fails;
  - a known path with the wrong method returns 405 with `Allow`;
  - `HEAD` runs the `GET` route.
- `src/Routing/Route.php`: each route regex is compiled once in the constructor, and parameters use `rawurldecode`.
- `src/Core/Application.php`: emits no body for `HEAD`.
- `app/Middlewares/PermissionMiddleware.php`: as a second line of defense, an empty permission list now denies
  access.

### FW-13 — Encoding and header edge cases

- `src/Http/Response.php`:
  - JSON uses `JSON_INVALID_UTF8_SUBSTITUTE`.
  - Each cookie is emitted as its own `Set-Cookie` header.
  - Cookie path, domain and `SameSite` are validated, and `SameSite=None` forces `Secure`.
- `src/Logging/RequestLogger.php`, `src/Logging/ErrorLogger.php`: log entries use `JSON_INVALID_UTF8_SUBSTITUTE`.
- CORS preflight for unknown paths was left unchanged: it reveals nothing and costs little.

### FW-14 — Configured time zone never applied

- `src/bootstrap.php`: `date_default_timezone_set('UTC')`.
- `src/Database/Database.php`: MySQL sessions run `SET time_zone = '+00:00'`; the value can be overridden per
  connection.
- `src/Database/Model.php`: `$dates` columns are returned as ISO 8601 UTC.
- `app/Models/EmailLog.php`: `sent_at` was added to `$dates`.
- `config.timezone` still names daily log files, appears in log timestamps, and is used in backup filenames. Date
  filters (`date=Y-m-d`) select UTC days.

### FW-15 — HTTPS not enforced

- `src/Core/Application.php`: when `force_https` is true, non-HTTPS requests get 403 before routing.
- `config/app.php`: added `force_https` (false locally); it is required to be true in production.

### FW-16 — Config reloaded by controllers

- `src/Core/Config.php` (new): read-only access by dot path.
- `src/Routing/Router.php`: controllers and middleware receive the application `Config` through constructor
  parameters typed `Config`. Other parameters keep their defaults.
- `src/Core/Application.php`: passes `new Config($config)` to the router.
- `app/Controllers/RateLimitController.php`, `app/Controllers/DatabaseBackupController.php`: take `Config` instead of
  re-requiring `config/app.php`.

### FW-17 — JWT hardening

- `src/Auth/JWT.php`:
  - checks the header size;
  - verifies the signature before decoding the payload JSON;
  - adds `kid` to issued tokens, resolves the verification key from `kid`, and accepts keys in `previous_keys` during
    rotation;
  - accepts tokens without a `kid` (issued before this change) with the current secret.
- `config/app.php`: added `jwt.kid` and `jwt.previous_keys`. Every secret must be at least 64 characters, which
  matches `bin2hex(random_bytes(32))`.

### FW-18 — `Model` boolean binding and hidden columns

- `src/Database/Model.php`:
  - `run()` binds by PHP type, with `bool` as `PARAM_INT` 0/1, `int` as `PARAM_INT`, and `null` as `PARAM_NULL`;
  - `$hidden` columns are removed from every result, and `rawFirstWhere()` is available for internal checks;
  - `all()` and `where()` take an optional limit.
- `app/Models/User.php`: `$hidden = ['password']`, and `authenticate()` uses `rawFirstWhere()`.
- `app/Models/User.php`, `Role.php`, `Permission.php`, `ActivityLog.php`, `EmailLog.php`: custom queries return rows
  through `presentAll()`.

### FW-19 — Database connection defaults

- `src/Database/Database.php`:
  - `PDO::ATTR_TIMEOUT` comes from the connection's `timeout` (default 5 seconds);
  - MySQL sessions use UTC;
  - `Database::transaction(callable, ?connection)` commits or rolls back, and joins a transaction that is already
    open.
- `config/app.php`: added `timeout` to the MySQL connection.

### FW-20 — Inconsistent backups and leftover plaintext files

- `src/Database/DatabaseBackup.php`:
  - one `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY` covers every table;
  - rows stream through an unbuffered query, with no `OFFSET` paging, in 100-row multi-value `INSERT` statements;
  - generated columns are skipped;
  - writes loop until complete, and `ZipArchive::close()` is checked;
  - the SQL file is created with `0600` and always deleted inside `createZIP()`;
  - the ZIP is created with `0600`;
  - only MySQL connections are accepted.
- `app/Controllers/DatabaseBackupController.php`:
  - deletes the ZIP in a shutdown function, so the cleanup also runs when the client disconnects;
  - streams the file with `sendFile()`;
  - logs failures through `ErrorLogger`.

### FW-21 — Migrator safety

- `src/Database/Migrations/Migrator.php`:
  - `migrate()` and `rollback()` run under MySQL `GET_LOCK`;
  - `status()` no longer creates the `migrations` table;
  - the new `rollbackPlan()` lists the batch that a rollback would reverse.
- `cli/migrate.php`: prints the rollback plan first and refuses to continue in production without `--force`.

### FW-22 — Validator performance and semantics

- `src/Validation/Validator.php`:
  - definitions are validated parent-first, and descendants of a failed field are skipped (Codex F07);
  - wildcard expansion appends to one list instead of re-copying it (Codex F08);
  - at most 1,000 items are allowed under one wildcard, and 10,000 fields in total;
  - `distinct` uses a strict hash set built with `serialize()` (Codex F08);
  - `unique` and `exists` results are memoized per value within one `validate()` call;
  - `required_if` and `required_unless` compare the referenced field after its own `integer`, `numeric` or `boolean`
    normalization (Codex F06).
- **Not done:** batching `exists` into one `IN (...)` query. With case-insensitive collations, matching values back
  in PHP would be unreliable, so the 1,000-item cap and the memoization bound the work instead.

### FW-23 — Request log: missing user, weak redaction, no retention

- `src/Http/Request.php`: the user and request id are kept in a context object that clones share, so the logger sees
  the user that authentication set.
- `src/Logging/Redactor.php` (new): redacts keys containing `password`, `secret`, `token`, `authorization`, `cookie`,
  `apikey`, `privatekey` or `credential`, plus the exact keys `otp`, `pin`, `cvv`, `cvc` and `ssn`. It is shared by
  `RequestLogger` and `Debugger`.
- `src/Logging/RequestLogger.php`:
  - multipart and form input is measured as logged, so it obeys `max_input_bytes`;
  - the User-Agent is capped at 500 bytes;
  - a client `X-Request-ID` is accepted only if it matches `[A-Za-z0-9-]{8,64}`;
  - old logs are pruned when each day's file is created.
- `src/Logging/LogFiles.php` (new): `prune()`.
- `config/app.php`: added `logging.retention_days` (30).

### FW-24 — Reading logs loads the whole file

- `src/Logging/LogFiles.php`: `tail()` reads backwards in 64 KB chunks and returns `limit` lines after `offset`,
  newest first. `countLines()` streams one line at a time to produce the total.
- `src/Logging/RequestLogger.php`: the new `page($date, $limit, $offset)` validates the date and returns
  `items` plus `total`.
- `app/Models/ServerLog.php`: delegates to `RequestLogger::page()`. `GET /server-logs` keeps its `limit`, `offset`
  and `date` pagination.

### FW-25 — SMTP robustness

- `src/Mail/SMTPMailer.php`:
  - socket writes loop until complete, and partial writes or timeouts raise `MailException`;
  - a failed `QUIT` after the server accepted the message (`250`) is ignored;
  - `encryption: 'none'` with a username is refused;
  - `EHLO` and `Message-ID` use `mail.ehlo_domain`, or the sender's domain, instead of `gethostname()`;
  - encoded headers are split into RFC 2047 words of at most 75 characters on UTF-8 boundaries, then folded.
- `config/app.php`: added `mail.ehlo_domain`.

## Documentation

- `README.md`: validation sources, body limit, 405 and `HEAD`, `X-Request-ID`, UTC timestamps, `$hidden` and
  `$dates`, `Database::transaction()`, `Config` injection, JWT key rotation, log redaction and retention, backups,
  upload policy, `sendFile()`, `migrate rollback --force`, `check-deploy`, and the production checklist.
- `PROJECT.md`: entries for the new files and the changed behavior of `Database`, `Model`, `Migrator`, logs and
  storage.

## Verification

- **Lint:** `php -l` passes on every PHP file in `app cli src config migrations public routes`, plus `cli/make`,
  `cli/rate-limit` and `cli/check-deploy` (PHP 8.4.4).
- **Harness 1 (37 checks pass):** `Application::dispatch()` with hand-built requests. It covers FW-01, 02, 04, 08, 09,
  11, 12, 13, 14, 15, 16, 17, 22, 23 and 24. Highlights:
  - 900 objects with `distinct` validate in 18 ms;
  - `tail` plus `count` on a 200,000-line file take 39 ms;
  - a JWT with a tampered payload fails at the signature check.
- **Harness 2 (15 checks pass):**
  - uploader rules, called through reflection, because `is_uploaded_file()` blocks tests outside a real upload;
  - SMTP header folding with Urdu text, the TLS-required check and the EHLO domain;
  - on SQLite: `Model` booleans, hidden columns and ISO dates, `Database::transaction()` rollback, a read-only
    `status()`, and `rollbackPlan()`.
- **Error handler, in a real PHP process:** a warning became an `ErrorException`, `@` was respected, and a
  memory-exhaustion fatal was written to `errors-*.log`.
- **Live requests through MAMP Apache:**
  - `GET /health/server` returned 200 with `X-Request-ID`, and `HEAD` returned 200;
  - `DELETE` returned 405 with `Allow: GET, HEAD, OPTIONS`;
  - `POST /auth/login?password=…` returned 422, and `hunter2` doesn't appear in the log;
  - a 2 MB JSON body returned 413;
  - `php cli/check-deploy http://localhost/expressphp` reported all 16 private paths blocked (403).

**Not verified here:** the MySQL-specific paths. The local server rejects the credentials in `config/app.php`, so
these didn't run against MySQL:

- strict-mode boolean binding;
- `SET time_zone`;
- `GET_LOCK`;
- the consistent-snapshot and unbuffered backup;
- generated-column detection.

Run `php cli/migrate.php status`, a user deactivation and a backup download against a real MySQL database before
release.
