# ExpressPHP framework review, round 2 (Claude)

Review date: 2026-10-13. Reviewer: Claude Opus 5.5.

Code reviewed: the working tree on `main` after the round-1 fixes (commit `4472b09`).

**Status:** every finding below, and the round-1 items FW-03, FW-05 and FW-07, are fixed in
[QA_FIXES_2.md](QA_FIXES_2.md). The findings describe the code as it was reviewed.

## Scope

Every framework file, re-read after the first round of fixes:

- `src/**`;
- `index.php`, `public/index.php` and both `.htaccess` files;
- `config/app.php`, including its startup checks;
- `cli/*`;
- `app/Middlewares/*`, which is the authentication and authorization layer;
- the consolidated migration.

API endpoints are out of scope: `routes/api.php`, `app/Controllers/*`, and the example models and view. They are cited
only where they show how a framework API behaves.

Round-1 findings use IDs FW-xx; the round-1 review and fix notes have been removed, and their history is in Git
(commits `4b2ccbd` to `4472b09`). New findings here use R2-xx.

## Verdict

The round-1 fixes hold. Both regression harnesses still pass (37 of 37 and 15 of 15).

This pass found **one high-severity problem that both earlier reviews missed**: the validator's unknown-key check is
quadratic. A request of about 0.5 MB costs about 14 seconds of CPU on any endpoint whose rules contain a wildcard or a
nested field. That code predates round 1.

The rest is smaller:

- secrets can reach the new error log through exception traces;
- logs and limiter files are created world-readable;
- a backup can silently commit a caller's transaction;
- some robustness and documentation gaps.

| # | Finding | Severity | Evidence |
|---|---|---|---|
| R2-01 | The unknown-key check is quadratic, and its error list amplifies the response | **High** | Verified |
| R2-02 | Exception traces in the error log contain argument values such as passwords | Medium | Verified |
| R2-03 | A NUL byte in a password causes a 500 (and, with R2-02, gets logged) | Medium | Verified |
| R2-04 | Logs, error traces and limiter records are world-readable | Medium | Verified |
| R2-05 | `DatabaseBackup` silently commits a caller's open transaction | Medium | Reasoned |
| R2-06 | Templates now fail with 500 on undefined variables; the README example triggers it | Low | Verified |
| R2-07 | SMTP STARTTLS accepts TLS 1.0 and 1.1 | Low | Verified |
| R2-08 | The request logger decodes up to 1 MB of JSON before checking its size limit | Low | Code |
| R2-09 | Log pruning deletes any dated `*.log` file in the configured directory | Low | Code |
| R2-10 | Deprecations are logged on every occurrence | Low | Code |
| R2-11 | Malformed claim types in a correctly signed JWT cause a 500 instead of a 401 | Low | Code |
| R2-12 | The MySQL connection has no read timeout, and the UTC session isn't verified on MySQL | Low | Reasoned |
| R2-13 | A client-supplied `X-Request-ID` is trusted as the log correlation id | Low | Code |
| R2-14 | Smaller items: fatal-error response headers, attachment names, view CSP, generator names, deploy check, no tests | Low | Code |

**Still open from round 1:**

- FW-03: rate-limiter keying, cleanup and storage.
- FW-05: refresh-token sessions.
- FW-07: the error `message` shape.

**Accepted by the owner:** the JWT secret and development defaults stay tracked in `config/app.php`, because the
repository will be private. Generate a new secret for each deployed environment, because the current value has already
been public.

**Evidence tags:**

- **Verified**: executed on PHP 8.4.4 from scripts outside the repo.
- **Code**: traced in the source.
- **Reasoned**: depends on MySQL behavior that wasn't executed. The local server rejects the tracked credentials.

---

## High

### R2-01 — The unknown-key check is quadratic, and its error list amplifies responses (High, Verified)

**Where:** `src/Validation/Validator.php:668-709` (`unknownKeyErrors`), and in particular line 701:
`$errors = [...$errors, ...self::unknownKeyErrors(...)]`.

**What:** Unknown fields are collected by recursion, and each level copies the whole accumulated error array. With one
unknown key per item, the cost grows with the square of the item count. The check runs **before** the round-1
protections: the 1,000-item wildcard cap, parent-first skipping, and the parent's own `max` rule. None of them limits
it.

Measured with the rules `x => optional|list|max:100`, `x.* => object`, `x.*.z => optional|string`:

| Items with one unknown key | JSON size | Time |
|---|---|---|
| 5,000 | 48 KB | 102 ms |
| 10,000 | 97 KB | 394 ms |
| 20,000 | 195 KB | 1,478 ms |
| 60,000 | about 0.5 MB | **13,798 ms** |
| 20,000 items using the allowed key `z` | 195 KB | 75 ms |

The 1 MB body limit allows about 100,000 items, which means roughly 40 seconds of CPU per request. A handful of
concurrent requests can occupy every PHP-FPM worker. Any endpoint whose rules contain `*` or a dotted path is exposed,
and validation runs before authorization only when the controller validates first.

The error list is also returned in full: a 978 KB body of 90,000 unknown top-level keys produced a **2.9 MB** 422
response with 90,000 messages.

**Fix:**

1. Collect errors by reference (`array &$errors`) instead of spreading arrays.
2. Stop after a fixed number of errors, such as 50, and add one "…and N more" message.
3. Apply `MAX_WILDCARD_ITEMS` inside the unknown-key walk, and cap the total number of nodes visited, before any
   per-item work.

Each step is a few lines in `unknownKeyErrors`.

## Medium

### R2-02 — Exception traces in the error log include argument values (Medium, Verified)

**Where:** `src/Logging/ErrorLogger.php:42` (`getTraceAsString()`), `src/Core/ErrorHandler.php`.

**What:** `zend.exception_ignore_args` is `Off` in MAMP's `php.ini`, and in PHP's development defaults. When that
setting is off, every trace frame records its arguments. A test exception thrown from `login('alice', 'hunter2-secret')`
produced the trace line `login('alice', 'hunter2-secret')`.

String arguments are shown up to 15 characters, which covers most passwords, OTPs, token prefixes and email addresses.
Any exception raised under `User::authenticate($username, $password)`, `Password::hash()`, `Password::verify()` or an
SMTP send therefore writes the secret into `storage/logs/errors-*.log`. Those files are readable by other local users
(R2-04).

**Fix:**

- Call `ini_set('zend.exception_ignore_args', '1')` in `ErrorHandler::register()`. It applies to exceptions created
  after the call.
- Mark secret parameters with `#[\SensitiveParameter]` in `Password::hash`/`verify`/`needsRehash`, `JWT::encode`'s
  secret handling, `SMTPMailer`, and example methods such as `User::authenticate`. PHP then shows those arguments as
  `Object(SensitiveParameterValue)` even when argument capture is on.

### R2-03 — A NUL byte in a password causes a 500 (Medium, Verified)

**Where:** `src/Auth/Password.php:23-33`.

**What:** On PHP 8.4, `password_hash()` throws `ValueError: Bcrypt password must not contain null character`. The
`string|min:8|max:72` rule accepts `"abcdefgh\0"`, so registration or a password change with a JSON `\u0000` returns
500 instead of 422. The `ValueError` is then written to the error log with the password in its trace (R2-02).

**Fix:** Have `Password::hash()` reject `"\0"` by throwing a `ValidationException`, so the client gets a 422. Have
`verify()` return `false` for such input. Mark both parameters `#[\SensitiveParameter]`.

### R2-04 — Runtime files are world-readable (Medium on shared hosting, Verified)

**What:**

- `storage/logs/*.log` and `storage/rate-limiter/*.json` exist as `-rw-r--r--`.
- `storage/logs/` is `drwxr-xr-x`.
- Log files are created by `file_put_contents` with the process umask.
- `RateLimiter.php:234` opens records with `fopen(..., 'c+')`, and `:317` creates its directory as `0775`.
- The loggers use `0750` only when they create the directory themselves.

Request logs (IPs, User-Agents, inputs) and error logs (paths, traces, R2-02) are readable by every account on the
host.

**Fix:** Call `umask(0027)` in `src/bootstrap.php`, which covers every file and directory the framework creates, or
`chmod` each new file to `0640`. Use `0750` for the limiter directory. Document `chmod -R o-rwx storage` for existing
installations.

### R2-05 — `DatabaseBackup` silently commits an open transaction (Medium, Reasoned)

**Where:** `src/Database/DatabaseBackup.php:88-89`.

**What:** `START TRANSACTION …` on a connection that already has an open transaction, for example inside
`Database::transaction()`, commits that transaction implicitly in MySQL. PDO still believes the transaction is open, and
the later `ROLLBACK` won't undo the caller's work. Nothing in the API warns about this.

**Fix:** Throw an exception from `createZIP()` when `$this->connection->inTransaction()` is true. Better still,
document that backups need their own connection, for example `Database::connection('backup')` pointing at the same
database.

## Low

### R2-06 — Templates fail on undefined variables, and the README example triggers it (Low, Verified)

**What:** With round 1's `ErrorHandler`, a template warning becomes an exception, so a missing variable returns 500. In
the test, rendering `example` without `cars` threw `RuntimeException (Undefined variable $cars)`.

The README "Render a PHP template" sample (`README.md:166`) passes only `title` and `message`, while
`app/Views/example.php:19` loops over `$cars`. Strict templates are a reasonable choice, but they need to be documented.

**Fix:** Use `$cars ?? []` in the example view. Add a README note that templates must handle optional variables
themselves.

### R2-07 — SMTP STARTTLS accepts TLS 1.0 and 1.1 (Low, Verified)

**Where:** `src/Mail/SMTPMailer.php:46`.

**What:** `STREAM_CRYPTO_METHOD_TLS_CLIENT` includes TLS 1.0 through 1.3 on this build, so a downgraded or outdated
relay negotiates a deprecated protocol. Certificate verification uses PHP's defaults, which verify the peer.

**Fix:** Pass `STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT`. For `ssl://`, set
`crypto_method` in a stream context.

### R2-08 — The request logger decodes the body before checking its size (Low, Code)

**Where:** `src/Logging/RequestLogger.php:114-117`.

**What:** `$request->input()` runs before the `max_input_bytes` check. The router's clone holds its own decode cache, so
the logger decodes the JSON body a second time, up to 1 MB, only to replace it with "payload too large". This happens
after `fastcgi_finish_request`, but it still occupies the worker.

**Fix:** Compare `strlen($request->text())` with the limit before calling `input()`.

### R2-09 — Log pruning is too broad (Low, Code)

**Where:** `src/Logging/LogFiles.php:24`.

**What:** Any `*YYYY-MM-DD.log` file in `logging.path` that is older than the retention window is deleted. If
`logging.path` points at a shared directory such as `/var/log/app`, other software's dated logs are deleted too.

**Fix:** Match only this framework's names: `^\d{4}-\d{2}-\d{2}\.log$` and `^errors-\d{4}-\d{2}-\d{2}\.log$`.

### R2-10 — Deprecations flood the error log (Low, Code)

**Where:** `src/Core/ErrorHandler.php:32`.

**What:** Each deprecation is logged every time it occurs. After a PHP upgrade, one deprecated call in the request path
writes a trace on every request.

**Fix:** Log each `file:line` once per process, using a static array of entries already seen.

### R2-11 — Malformed claim types in a valid JWT cause a 500 (Low, Code)

**Where:** `src/Auth/JWT.php:109`, `app/Middlewares/AuthMiddleware.php:36-37`.

**What:** After the signature check, array-valued `iss`, `sub` or `sv` claims are cast to strings or integers. The
`Array to string conversion` warning becomes an `ErrorException`, which `AuthMiddleware` doesn't catch, so the response
is 500 rather than 401. Only someone holding the signing key can produce such a token, so this is robustness rather than
exposure.

**Fix:** In `validateClaims()`, require `iss` and `sub` to be strings, `aud` to be a string or a list of strings, and
`sv` to be an integer. Throw `JWTException` otherwise.

### R2-12 — Database timeouts and UTC session aren't verified (Low, Reasoned)

**Where:** `src/Database/Database.php:53` and `:64`.

**What:** `PDO::ATTR_TIMEOUT` limits only the connect phase for pdo_mysql. A slow query or a stalled server still holds
the worker until `max_execution_time`, and PHP-FPM may not count database time toward that limit. The prepared
`SET time_zone = ?` is valid MySQL, but it hasn't been run against the target server.

**Fix:** Optionally set `SET SESSION max_execution_time = <ms>` for read queries (MySQL 5.7.8 and later). Before release,
verify `SELECT @@session.time_zone` returns `+00:00` through the application connection.

### R2-13 — Client request ids are trusted for log correlation (Low, Code)

**Where:** `src/Http/Request.php:104-112`.

**What:** A client can send `X-Request-ID` (8–64 characters from `[A-Za-z0-9-]`) and reuse another request's id, which
mixes up the logs that support staff read. Injection isn't possible.

**Fix:** Always generate the server id. Log a valid client value separately as `client_request_id`.

### R2-14 — Smaller items (Low, Code)

- **Fatal-error response:** `ErrorHandler`'s shutdown response (`src/Core/ErrorHandler.php:46-50`) lacks
  `X-Request-ID`, `X-Content-Type-Options` and CORS headers. A browser client sees a CORS error instead of the 500.
- **Attachment names:** `Response::attachment()` sends non-ASCII filenames raw. Add
  `filename*=UTF-8''<rawurlencoded>` alongside an ASCII fallback.
- **View CSP:** `view()` HTML responses carry no `Content-Security-Policy`. Consider `default-src 'self'` as the default
  for views.
- **Generator names:** `MakeCommand` still accepts reserved words, such as `class`, as class names (Codex F31).
- **Deploy check:** `cli/check-deploy` probes directories but not concrete files such as today's log or a backup ZIP.
  That's harmless while the root `.htaccess` rule blocks all of `storage/`, but worth adding.
- **Tests:** the repository has no automated tests. The two harnesses used in rounds 1 and 2 could be committed as a
  small `tests/` runner with no dependencies.

---

## Round-1 fixes re-checked

| Area | Result |
|---|---|
| FW-01 query/body separation, URL not logged | Holds: harness and live request |
| FW-02 right-to-left `X-Forwarded-For`, CIDR | Holds |
| FW-04 error logging, display off | Holds; see R2-02, R2-04 and R2-10 for side issues |
| FW-06 `.htaccess` and `check-deploy` | Holds: all 16 paths blocked earlier today |
| FW-08 / FW-09 / FW-13 response fixes | Hold |
| FW-10 uploader fails closed | Holds |
| FW-11 body cap, single JSON decode | Holds; the validator still has R2-01 |
| FW-12 405, `HEAD`, duplicate and empty-middleware checks | Hold |
| FW-14 / FW-18 / FW-19 UTC, model binding, transactions | Hold on SQLite; MySQL not executed (R2-12) |
| FW-15 / FW-16 / FW-17 HTTPS, Config injection, JWT `kid` | Hold |
| FW-20 / FW-21 backup and migrator | Logic reviewed; MySQL not executed; see R2-05 |
| FW-22 validator caps | Hold for value validation; the unknown-key path wasn't covered (R2-01) |
| FW-23 / FW-24 / FW-25 logging, log pages, SMTP | Hold; see R2-07, R2-08 and R2-09 |

## Controls that held

- **SQL:** values are always bound with types, identifiers go through an allowlist, and emulated prepares are off.
- **JWT:** HS256 is pinned, the header size is bounded, the signature is checked before the payload is parsed, the
  comparison is constant-time, the time claims plus issuer and audience are validated, and `kid` rotation works.
- **Request input:** the body and query are never merged for validation, the body size is capped, `X-Forwarded-For` is
  read from the right through trusted CIDR ranges, and the request id is restricted to safe characters.
- **Responses:**
  - streams can't leak through a replacement body;
  - downloads take their type from the extension and are served as sandboxed attachments;
  - JSON output survives invalid UTF-8;
  - cookies are validated and emitted separately;
  - security headers, HSTS over HTTPS, and `force_https` are in place.
- **Uploads:** strict allowlists, executable and markup extensions always blocked, MIME type detected and matched to
  the extension, OOXML structure checked, random stored names, all-or-nothing batches, and private file modes.
- **Views:** path containment, reserved names, and output-buffer safety.
- **Operations:** PHP errors are never displayed, and exceptions and fatal errors are logged with a request id. Logs are
  redacted, paged without loading whole files, and pruned after the retention period.
- **Data tools:** the migration lock, rollback plan and production guard; the snapshot-consistent, streaming backup with
  temporary-file cleanup.
- **CLI:** every script exits unless run from the command line.

## Suggested order

1. **R2-01.** This is a denial-of-service risk today on any endpoint with nested or wildcard rules.
2. **R2-02, R2-03, R2-04.** Together these keep secrets out of readable files.
3. **R2-05 and R2-07.**
4. **R2-06 and R2-08 through R2-14**, together with the open round-1 items FW-03, FW-05 and FW-07.

## Verification performed

- **Lint:** clean on every PHP file.
- **Regression harnesses:** 37 of 37 and 15 of 15 passed.
- **New probes:**
  - argument capture in traces (R2-02);
  - the `password_hash` NUL `ValueError` (R2-03);
  - file modes in `storage/` (R2-04);
  - strict template rendering (R2-06);
  - TLS constant flags (R2-07);
  - unknown-key timing at 5,000, 10,000, 20,000 and 60,000 items, with `unknownKeyErrors` timed on its own (R2-01).
- **Not executed:** MySQL paths (R2-05, R2-12), and load testing beyond the single-request timings above.

## File-by-file coverage

| File | Result |
|---|---|
| `index.php`, `public/index.php` | OK |
| `.htaccess`, `public/.htaccess` | OK |
| `config/app.php` | Checks OK; secret tracked (accepted) |
| `src/bootstrap.php` | R2-04 (umask) |
| `src/helpers.php` | OK |
| `src/Core/Application.php` | OK |
| `src/Core/Config.php` | OK |
| `src/Core/ErrorHandler.php` | R2-02, R2-10, R2-14 |
| `src/Core/Debugger.php` | OK; FW-07 open |
| `src/Core/MakeCommand.php` | R2-14 |
| `src/Http/Request.php` | R2-13 |
| `src/Http/Response.php` | R2-14 |
| `src/Http/IPAddress.php` | OK |
| `src/Http/CORS.php`, `Pagination.php`, `HttpException.php` | OK |
| `src/Routing/Router.php`, `Route.php` | OK |
| `src/Auth/JWT.php` | R2-11 |
| `src/Auth/AccessToken.php` | OK; FW-05 open |
| `src/Auth/Password.php` | R2-02, R2-03 |
| `src/Auth/JWTException.php` | OK |
| `src/Database/Database.php` | R2-12 |
| `src/Database/Model.php` | OK |
| `src/Database/DatabaseBackup.php` | R2-05 |
| `src/Database/Migrations/Migrator.php`, `Migration.php` | OK |
| `src/Validation/Validator.php` | **R2-01** |
| `src/Validation/ValidationException.php` | OK |
| `src/Logging/RequestLogger.php` | R2-04, R2-08 |
| `src/Logging/ErrorLogger.php` | R2-02, R2-04 |
| `src/Logging/LogFiles.php` | R2-09 |
| `src/Logging/Redactor.php` | OK |
| `src/RateLimit/RateLimiter.php`, `RateLimitDecision.php` | R2-04; FW-03 open |
| `src/Storage/FileUploader.php`, `FileUploadException.php` | OK |
| `src/Mail/SMTPMailer.php` | R2-07 |
| `src/Mail/MailException.php` | OK |
| `src/View/View.php` | R2-06, R2-14 |
| `app/Middlewares/AuthMiddleware.php` | R2-11 |
| `app/Middlewares/PermissionMiddleware.php`, `RoleMiddleware.php` | OK |
| `migrations/2026_08_25_000001_init_project.php` | OK |
| `cli/migrate.php`, `cli/rate-limit` | OK |
| `cli/make` | R2-14 |
| `cli/check-deploy` | R2-14 |
