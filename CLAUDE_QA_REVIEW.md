# ExpressPHP framework core review (Claude)

Review date: 2026-10-13. Reviewed commit: `4b2ccbd` on `main`. Reviewer: Claude Opus 5.5.

**Status:** fixes for every finding except FW-03, FW-05 and FW-07 are recorded in [QA_FIXES.md](QA_FIXES.md). The
findings below describe the code as it was reviewed.

## Scope

This review covers the framework that every future endpoint will inherit:

- `src/`;
- the entry points (`index.php`, `public/index.php`, both `.htaccess` files);
- the boot and startup checks in `config/app.php`;
- the CLI runners in `cli/`.

The routes, controllers, models and seed data in `app/`, `routes/` and `migrations/` are treated as **examples to be
replaced**. Their endpoint design isn't reviewed. They are cited only where they demonstrate how a core API behaves in
practice.

Per the owner's decisions, the following are **out of scope**:

- keeping `config/app.php` with its secrets in Git, because the repository will be private;
- not using `.env`;
- the RBAC rules of the example endpoints.

**One action still applies:** the `jwt.secret` currently in `config/app.php` has already been published on the public
GitHub remote. Making the repository private later won't retract copies that already exist. Generate a new secret for
each deployed environment with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`.

## Verdict

The core is small and readable. The parts that matter most are done correctly: the SQL identifier allowlist, bound
parameters, JWT verification, view path containment, upload naming, CORS matching and validation of rule definitions.

The problems fall into six themes:

1. **Request input and identity:** query strings merged into body validation, trust in the leftmost forwarded-IP
   hop, and no limit on body size.
2. **Operations:** exceptions are never logged, the request log loses the user, and time zones are never applied.
3. **Rate limiting:** keyed only on IP, never cleaned up, and a single lock per IP. This is the wrong design for
   traffic from mobile carriers.
4. **Response contract:** the `message` field changes type, a stream survives being replaced by an error, files are
   served using a sniffed content type, and an invalid UTF-8 byte produces a 500.
5. **Defaults that fail open:** the uploader accepts every file type when its lists are empty, an empty permission
   list allows everyone, and per-upload MIME options are silently ignored.
6. **Authentication primitives:** a single long-lived token with a global per-user revocation counter. There's no
   refresh-token or per-device session primitive for mobile apps to build on.

### Fix before building production endpoints on the core

| # | Finding | Severity |
|---|---|---|
| FW-01 | Query string is merged into body validation, and the logged URL isn't redacted | High |
| FW-02 | The client chooses its IP address once a trusted proxy is configured | High (conditional) |
| FW-03 | Per-IP rate limiting is the wrong design for mobile traffic | High |
| FW-04 | Exceptions are never logged, and PHP's error display isn't controlled | High |
| FW-05 | The auth primitives can't express per-device sessions or refresh tokens | High (for mobile) |
| FW-06 | `.htaccess` is the only boundary around `.git`, config and storage | High (conditional) |
| FW-07 | The error `message` field changes type | Medium |
| FW-08 | A streamed response survives being replaced by an error | Medium |
| FW-09 | `sendFile()` serves files inline using a sniffed type | Medium |
| FW-10 | `FileUploader` fails open, and per-upload MIME options are ignored | Medium |

## How to read this review

Each finding is tagged with how it was established:

- **Verified**: executed on PHP 8.4.4 using scripts outside the repo. No project files or database rows were changed.
- **Code**: traced through the source.
- **Reasoned**: depends on MySQL or Apache behavior that I couldn't execute here. The local MySQL rejected the tracked
  credentials, and I didn't try others.

Severity is measured against the framework's own promises: secure, production-ready, and explicit.

Earlier reviews: `CODEX_QA_REVIEW.md` and `CURSOR_QA_REVIEW.md` reviewed the same code. No code has changed since then,
so their core findings remain open. Overlaps are noted as **Codex Fxx** or **Cursor Cxx**.

---

## Request and routing — `src/Http/Request.php`, `src/Routing/*`

### FW-01 — Query string merged into body validation; secrets logged in the URL (High, Verified)

**Where:** `Request.php:110-120` (`array_replace_recursive($this->query, $input)`), `RequestLogger.php:61`.

**What:** In the harness, a `POST` with an empty JSON body and `?a=…&b=secret` passed `validate()` with both values. So
every write endpoint built on the core accepts its fields from the URL, including fields like `password` and `otp`. The
request logger then writes `originalURL()` unredacted; only the separate `query` field is redacted. URLs also end up in
proxy, CDN and Apache access logs, which the framework can't redact.

**Fix:** Make `validate()` read the body only for `POST`, `PUT`, `PATCH` and `DELETE`, and the query only for `GET`.
Keep `validateParams()` for route parameters plus the query. Log the path with the query removed or redacted.

Cross-reference: Codex F05, Cursor C09.

### FW-02 — `ip()` trusts the leftmost `X-Forwarded-For` entry (High when proxied, Verified)

**Where:** `Request.php:228-238`. `protocol()` at `:291-301` uses the same pattern.

**What:** In the harness, a trusted proxy appended the real client `203.0.113.5` after a forged value of `6.6.6.6`, and
`ip()` returned `6.6.6.6`. As soon as `trusted_proxies` is set, clients choose their own rate-limit identity and logged
IP. `trusted_proxies` also matches exact strings only, with no CIDR ranges, while Cloudflare and most load balancers
publish CIDR ranges.

**Fix:** Walk the header from the right, skipping trusted proxies, and return the first untrusted address. Support CIDR
matching. Normalize IPv6 with `inet_pton`/`inet_ntop`.

Cross-reference: Codex F04/F14, Cursor C08.

### FW-11 — No body size limit; JSON decoded twice; quadratic path lookup (Medium, Code)

**Where:** `Request.php:35`, `:82-84`, `:132-151`; `Validator.php:96-99`.

**What:**

- `php://input` is read in full.
- The body is decoded twice: once as arrays and once as objects, to collect object paths.
- The path list is searched with `in_array` once for every `object`/`list` check, so a body with N objects costs
  O(N²).
- `post_max_size` is the only limit.

A 7 MB JSON array of small objects therefore uses a lot of memory and CPU per request.

**Fix:**

- Add a `max_body_bytes` config value and reject larger requests with 413, based on `Content-Length` or a capped read.
- Decode once as objects and convert to arrays while recording paths.
- Store paths as array keys so lookups are O(1).

Cross-reference: Codex F09, Cursor C07.

### FW-12 — Router contract gaps (Medium, Verified + Code)

- **An empty middleware parameter list fails open.** In the harness, `'permission:'` became `[]`
  (`Router.php:198`), and `PermissionMiddleware` let every authenticated user through. The router should reject an
  alias that ends in an empty `:` when routes are registered. (Codex F15)
- **Route parameters are decoded with `urldecode`** (`Route.php:35`). In the harness, `a+b%2Bc` became `"a b+c"`. Use
  `rawurldecode`, which also turns `%2F` into `/` inside a single segment, so document that behavior. (Codex F30)
- **No 405 response.** A wrong method returns 404 with no `Allow` header, and `HEAD` isn't routed. Mobile client
  libraries treat 405 and 404 differently.
- **Linear matching.** Each route's regex is rebuilt on every request (`Route.php:23-28`). That's negligible at 50
  routes, but it's worth compiling patterns once at registration time.
- **No duplicate-route detection.** A later registration of the same path is silently unreachable.

## Responses — `src/Http/Response.php`, `src/Http/CORS.php`

### FW-07 — `message` is sometimes a string and sometimes an array (Medium, Verified)

**Where:** `Response.php:60-73`; `Debugger.php:36` always returns an array.

**What:** In the harness, one validation error produced `"message": "…"` and two errors produced `"message": [ … ]`.
Typed Android decoders such as kotlinx.serialization, Moshi and Gson data classes fail on this field, or each app has to
write a custom adapter.

**Fix:** Make `message` always a string. Add `errors`, ideally shaped as `{field: [messages]}`, so `ValidationException`
can keep the field name that `Validator::addError` already knows.

### FW-08 — A stream survives being replaced by an error (Medium, Verified)

**Where:** `Response.php:166-173` and `:198-208`. `error()`, `json()`, `text()` and `html()` never clear
`streamCallback`; only `view()` does.

**What:** In the harness, `stream(...)` followed by `error('Not allowed', 403)` emitted status 403 with the **original
streamed body**. The `Content-Length` and `Content-Disposition` headers from `sendFile()` survive too. Any middleware or
controller that prepares a download and then decides to refuse it will send the file anyway.

**Fix:** Have every method that sets a body clear `streamCallback`, `Content-Length` and `Content-Disposition`.

Cross-reference: Codex F26.

### FW-09 — `sendFile()` serves files inline using a sniffed MIME type (Medium, Verified)

**Where:** `Response.php:142-159`.

**What:** In the harness, a `.txt` file containing `<script>` was served as `Content-Type: text/html` with no
`Content-Disposition`. If a future endpoint serves user uploads through `sendFile($path)` without a download name, this
is stored XSS on the API origin.

**Fix:**

- Use the type recorded at upload time, or map from the extension.
- Default to `attachment`.
- Send `Content-Security-Policy: sandbox` on file responses.

### FW-13 — Encoding and header edge cases (Low–Medium, Verified)

- **Invalid UTF-8 causes a 500.** `Response::json()` without `JSON_INVALID_UTF8_SUBSTITUTE` throws `JsonException` on
  one invalid byte (verified). One bad database row or header value becomes a 500, and in `RequestLogger` it silently
  drops the log entry. (Codex F13)
- **Cookies are merged.** Two `cookie()` calls produced the single header `a=1; …, b=2; …` (verified). Browsers and
  OkHttp's cookie jar treat that as one malformed cookie. Store `Set-Cookie` values as a list and call
  `header(..., false)` for each. (Codex F28)
- **Unvalidated cookie attributes.** `cookie()` writes `domain` and `same_site` without validation, and
  `SameSite=None` isn't forced to `Secure`.
- **Preflight answers every path.** CORS preflight returns 204 for any path, including routes that don't exist.
  That's harmless, but preflights skip the rate limiter.

## Application lifecycle — `src/Core/Application.php`, `public/index.php`

### FW-04 — Exceptions are never logged, and PHP's error display isn't controlled (High, Code)

**Where:** `Application.php:122-130`, `public/index.php:18-20`. Nothing in the codebase calls `error_log`,
`set_error_handler`, `set_exception_handler` or `ini_set('display_errors')`.

**What:**

- Every unexpected `Throwable`, including any config error at startup, becomes a bare 500 and is never recorded. The
  request log records only the status, so production failures can't be diagnosed.
- If the host's `php.ini` has `display_errors=On`, which is common on shared hosting, any warning prints a file path and
  message ahead of the JSON. That breaks the client's parser and leaks paths.
- Exceptions thrown while streaming happen in `emit()`, after the status line has gone out, so the client sees a 200
  with a truncated body. (Codex F27)

**Fix:**

1. In the front controller, set `ini_set('display_errors', '0')`, `error_reporting(E_ALL)`, and an error handler that
   converts warnings into `ErrorException`.
2. Write a private error log containing the class, message, file:line, request id and redacted context.
3. Return the request id in an `X-Request-ID` response header, so a user's bug report can be matched to the log line.

### FW-14 — The configured time zone is never applied (Medium, Verified + Code)

**What:** `config.timezone` is passed only to `Debugger`, `RequestLogger` and the example backup code. Nothing calls
`date_default_timezone_set`, and the PHP default here is `UTC` (verified). `Database::connection()` never sets the MySQL
session `time_zone`.

As a result, PHP `DateTimeImmutable()` values, MySQL `CURRENT_TIMESTAMP` and the `Y-m-d` date filters can each use a
different zone. Timestamps are also returned without an offset.

**Fix:** At boot, call `date_default_timezone_set('UTC')` and run `SET time_zone = '+00:00'` on connect. Serialize
timestamps as ISO 8601 with `Z`. Keep `config.timezone` for display only, such as log file names.

### FW-15 — HTTPS isn't enforced (Medium, Code)

**Where:** `Application.php:201-215`.

**What:** HSTS is sent only on requests that already arrived over HTTPS, and plain HTTP is served normally.

**Fix:** Add a `force_https` config option, on by default when `env` is `production`. It should reject non-HTTPS
requests with 403 rather than redirecting, because API clients would silently resend their credentials to the redirect
target.

### FW-16 — Config is loaded once per controller instead of once per process (Low, Code)

**What:** The core gives controllers no way to read the config, so the examples call `require config/app.php` again
(`RateLimitController.php:21`, `DatabaseBackupController.php:26`). That re-runs every validation check, and the
pattern will spread to new code.

**Fix:** Give the `Application` config to controllers. The router already constructs them, so it could pass the config
in, or expose a read-only `Config` object.

## Authentication — `src/Auth/*`

### FW-05 — No session or refresh-token primitive (High for mobile apps, Code)

**Where:** `AccessToken.php`, `JWT.php`, and the core contract implied by the `session_version` claim.

**What:** The core provides one kind of token: a long-lived (`ttl` 86400) HS256 access token, revoked by a single
per-user counter. Any app built on it inherits these problems:

- Refreshing or logging out on one device logs out every device, because the counter is per user.
- There's no way to refresh after the access token expires, so users must log in again daily.
- A stolen token can't be revoked on its own.
- There's no absolute session lifetime.

**Fix:** Add a `Session` primitive to `src/Auth`:

- a table of per-device sessions holding a hashed refresh token, expiry and last-used time;
- short access tokens carrying a `sid` claim;
- refresh-token rotation, where reuse of an old token revokes the session.

Keep `session_version` as the "revoke everything" switch for password changes.

Cross-reference: Cursor C05.

### FW-17 — Smaller JWT issues (Low, Code)

- **Parse before verify.** `JWT.php:60-71` decodes the header and payload JSON before checking the signature. Verify
  first, so unauthenticated input never reaches `json_decode`.
- **No `kid`.** Keys can't be rotated without invalidating every token. Accept a list of `{kid: secret}` pairs and sign
  with the current one.
- **Characters counted, not bytes.** The secret check at `config/app.php:196` counts characters. A 32-character hex
  string is only 16 bytes of entropy, despite the message. Require 64 hex characters, or decode the hex and check
  `strlen`.
- **No `typ`/`alg` binding to the issuer.** That's acceptable for tokens this API issues itself; document that tokens
  from third parties aren't supported.

## Rate limiting — `src/RateLimit/RateLimiter.php`

### FW-03 — Per-IP, file-based limiting is the wrong design for mobile traffic (High, Code)

**What:**

- **Carrier NAT.** Each IP is a single bucket: 120 requests per minute, then a 5-minute pause, then a 30-minute block
  after 5 violations. Pakistani carriers put large numbers of subscribers behind shared IPv4 addresses, so a popular app
  will block its own users.
- **IPv6.** Every address gets its own record, so a client with a /64 is effectively unlimited, and each new address
  creates a new file.
- **Storage never cleaned.** One file per IP, never deleted. `blocked()` reads every file (`:196-213`).
- **Serialized requests.** Every request does `fopen`, an exclusive `flock`, a read and a write (`:230-261`). All
  requests from one carrier-NAT IP queue on the same lock.
- **Unchecked writes.** The results of `fwrite` and `ftruncate` are never checked. A full disk silently resets the
  counters, and a corrupted record decodes as a fresh one (`:286-298`), so the limiter fails open. (Codex F03)
- **One server only.** The files are local, so limits don't hold across more than one web node.
- **No user identity.** The limiter runs before routing and authentication, so it can't apply per-user limits.

**Fix:**

- Use a `key` resolver: the user id when authenticated, otherwise IPv4 or the IPv6 /64.
- Allow named limits per route group, for example `login: 5/min per username + 30/min per IP`.
- Return a plain 429 with `Retry-After` for anonymous traffic instead of escalating blocks.
- Expire records older than the decay window.
- Check every write and fail closed on write errors for routes in `fail_closed`.
- For more than one node, back the limiter with MySQL (an `INSERT … ON DUPLICATE KEY UPDATE` counter) or APCu.

Cross-reference: Codex F03/F04/F14, Cursor C06.

## Database — `src/Database/*`

### FW-18 — `Model` binds booleans as strings and has no hidden columns (Medium, Reasoned + Code)

**Where:** `Model.php:88`, `:109`, `:119`, `:89`.

**What:**

- **Booleans.** `execute($attributes)` binds every value as a string, and `false` becomes `''`. In MySQL strict mode,
  the default, inserting `''` into a `TINYINT` raises error 1366, so writing `false` to any boolean column through
  `Model` fails with a 500. `true` becomes `"1"` and works. Not executed here.
- **No hidden columns.** `create()` returns `find()`, which is `SELECT *`. A framework user who returns
  `$model->create(...)` directly leaks columns such as password hashes, tokens and internal flags. The example `User`
  model has to strip these by hand.
- **Unbounded reads.** `all()` and `where()` have no limit; a framework user on a large table gets an unbounded
  result.

**Fix:**

- Bind with `bindValue()`, choosing `PARAM_INT`, `PARAM_BOOL` or `PARAM_NULL` from each value's PHP type.
- Add `protected array $hidden = []`, applied to every returned row.
- Give `where()` an optional limit.

### FW-19 — `Database` connection defaults (Low–Medium, Code)

**Where:** `Database.php:21-58`.

**What:**

- There's no `PDO::ATTR_TIMEOUT`, so a stalled database holds every PHP worker.
- `time_zone` and `sql_mode` are never set (FW-14), so behavior depends on server defaults.
- There's no transaction helper. Apps write action-plus-audit pairs without atomicity, and a failure in the second
  write returns a 500 for an action that was committed.
- There's no reconnect, which matters for long CLI runs.

**Fix:** Set defaults for the timeout and `MYSQL_ATTR_INIT_COMMAND` (`SET time_zone='+00:00', sql_mode=…`). Add
`Database::transaction(callable $fn, ?string $connection = null)`.

### FW-20 — `DatabaseBackup` produces inconsistent dumps and leaves plaintext files (Medium, Code)

**Where:** `DatabaseBackup.php:26-133`.

**What:**

- **Leftover plaintext file.** `createZIP()` returns `sql_path`, so deleting the plaintext SQL file on success is the
  caller's job, and a caller that forgets leaves a full plaintext dump in `storage/backups`. The example caller deletes
  it only inside its stream callback, which doesn't run if the client disconnects.
- **Inconsistent rows.** Rows are paged with `LIMIT/OFFSET`, with no `ORDER BY` and no snapshot transaction, so pages
  can skip or repeat rows, and tables are captured at different moments. `OFFSET` paging is also quadratic.
- **Unchecked writes.** Short `fwrite` writes and the return value of `ZipArchive::close()` aren't checked.
- **Generated columns.** Generated columns are exported as values, which can't be restored.

**Fix:**

- Delete the SQL file inside `createZIP()` once `close()` succeeds.
- Use `START TRANSACTION WITH CONSISTENT SNAPSHOT` and keyset paging by primary key, and skip generated columns.
- Check every write.
- Document that HTTP is the wrong transport for large databases; prefer the CLI with `mysqldump --single-transaction`.

Cross-reference: Codex F17-F19.

### FW-21 — `Migrator` safety (Medium, Code)

**Where:** `Migrator.php`, `cli/migrate.php`.

**What:**

- There's no lock, so two concurrent `migrate` runs (for example two deploy hooks) can apply the same migration twice.
  (Codex F20)
- Each migration is recorded only after `up()` succeeds, with no transaction. MySQL DDL commits automatically anyway,
  so document that `up()` should be safe to run again.
- `rollback` runs `down()` with no confirmation and no environment check, and the consolidated migration's `down()`
  drops every table. One mistyped command in a production shell deletes all data.
- `status` creates the `migrations` table, so a read-only command writes to the database.

**Fix:**

- Wrap runs in `GET_LOCK('expressphp_migrate', 10)`.
- Require `--force` for `rollback` when `env` is `production`, and print the migrations that will be reversed first.

### FW-22 — Validator performance and semantics (Medium, Code)

**Where:** `Validator.php`.

**What:**

- `expandField` builds its path arrays with spread inside loops, and `distinct` uses `in_array` over the values seen
  so far. Both are O(N²) for large wildcard lists. (Codex F08)
- `unique` and `exists` run one SQL query per item in a wildcard list: `items.*.id => exists:…` with 1,000 items runs
  1,000 queries.
- When a parent value is invalid, child rules still run, including database checks. (Codex F07)
- Conditional rules accept numeric aliases such as `1` and `0` for booleans, so `required_if:flag,true` can be skipped
  with input like `"flag": "1.0"`. (Codex F06)

**Fix:**

- Store seen values in a hash for `distinct`.
- Batch `exists` into a single `IN (...)` query per pattern.
- Skip child rules once a parent fails.
- Cap the size of wildcard lists.

## Logging and debugging — `src/Logging/RequestLogger.php`, `src/Core/Debugger.php`

### FW-23 — The request log loses the user, redacts too little and keeps everything (Medium, Verified + Code)

**What:**

- **The user is lost.** `Application::run()` logs the original `Request`, but the router clones it (`Router.php:106`)
  and authentication sets the user on the clone. In the harness, the original's user was `null` while the controller
  saw `{"id":7}`, so `user_id` is always `null` in the logs. (Codex F12)
- **Exact-name redaction.** Redaction matches whole key names (`:16-26`), so `new_password`, `current_password`, `otp`,
  `pin` and `*_token` are logged in clear. `Debugger::sensitive()` already matches by substring; the two should share
  one implementation.
- **Multipart bypasses the size limit.** The byte limit checks the raw body, which is empty for multipart requests, so
  every `$_POST` field is logged in full. (Codex F10)
- **Bad entries are dropped silently.** An invalid UTF-8 byte in a User-Agent or input makes `json_encode` throw, and
  the entry is discarded without trace. (Codex F13)
- **No retention.** IP addresses and User-Agents are kept forever, which conflicts with the Play Data safety
  disclosures.
- **Client-supplied request id.** `request_id` is taken from the client's `X-Request-ID` without a length or character
  check.

**Fix:**

- Have `dispatch()` return the final request, or keep a request context.
- Share a substring redactor between the logger and the debugger, and cap multipart input.
- Use `JSON_INVALID_UTF8_SUBSTITUTE`.
- Add a configurable `retention_days` cleanup.
- Generate the request id on the server, or accept the client's only if it matches `[A-Za-z0-9-]{1,64}`.

### FW-24 — Reading logs loads the whole file (Low–Medium for performance, Code)

**What:** `ServerLog` is an example model, but it shows the only way to read logs with the core: `file()` followed by
`array_reverse()` on the whole day's log to return 20 lines. On a busy day that exhausts `memory_limit`.

**Fix:** Add a reverse line reader to `RequestLogger` that seeks from the end of the file.

Cross-reference: Codex F11.

`Debugger` itself is sound: it's off unless `debug` is on, escapes its HTML, and redacts keys. When it's disabled,
`dd()` returns a generic 500, so a forgotten `dd()` doesn't leak data.

## Uploads — `src/Storage/FileUploader.php`

### FW-10 — Fail-open defaults; per-upload MIME options ignored (Medium, Code)

**What:**

- **Empty lists allow everything.** `validateAllowed()` passes when the allowed list is empty (`:111`). If a
  deployment's config omits both `allowed_extensions` and `mime_types_by_extension`, every file is accepted, including
  `.php`, `.phtml` and `.html`. Storage is private today, but one misconfigured web server or `sendFile()` route (FW-09)
  turns that into code execution or XSS.
- **Per-upload MIME options are ignored.** Options passed to `upload($file, $dir, $options)` are merged over the
  global config. `validateMIMEType()` uses the global extension map whenever one exists, so a per-call
  `allowed_mime_types` is silently ignored, even though `FileController.php:32-44` shows it as an example. (Codex F22)
- **Partial batch uploads.** If one file in `uploadMany()` fails, the files already stored remain. (Codex F23)
- **Readable permissions.** Files are created `0644` and directories `0775` (`:41`, `:162`), so other accounts on shared
  hosting can read uploads.
- **Loose Office types.** `docx` and `xlsx` accept any ZIP file.

**Fix:**

- Reject uploads when no allowlist is configured, and always reject executable or markup extensions.
- Let per-call options replace the extension map.
- Roll back files already stored when a batch fails.
- Use `0640` and `0750`.
- Return a record that the app can store, so ownership can be enforced when the file is served.

## Mail — `src/Mail/SMTPMailer.php`

### FW-25 — SMTP robustness (Low–Medium, Code)

**What:**

- `fwrite` results are never checked, and TLS streams can write partially. The message can be truncated while still
  reported as sent. (Codex F24)
- A failed `QUIT` after the server accepted the message (`250`) reports a failure, so callers may resend.
  (Codex F25)
- `encryption: 'none'` combined with a username sends `AUTH LOGIN` credentials in clear.
- Encoded subject headers are never folded, which breaks lines over 998 characters. (Codex F32)
- `EHLO` sends `gethostname()`, which leaks internal host names.
- The connection error includes the SMTP host and port. That's harmless in the core, but apps must not echo it to
  clients.

**Fix:**

- Write in a loop until all bytes are sent.
- Treat a failed `QUIT` after 250 as success.
- Refuse authentication without TLS.
- Fold encoded headers.
- Make the `EHLO` name configurable.

## View — `src/View/View.php`

Sound:

- The template name is restricted by regex and resolved with `realpath` inside the views root.
- Reserved variable names are rejected.
- `extract(EXTR_SKIP)` can't overwrite `$data` or `$escape`.
- Output buffers are restored when rendering fails.

One gap: HTML responses carry no `Content-Security-Policy`. Consider `default-src 'self'` for `view()` responses.

## Deployment boundary — `.htaccess`, `index.php`, `public/`

### FW-06 — `.htaccess` is the only barrier (High if `.htaccess` is ignored, Code)

**What:** With the project root as the document root, as the README recommends, the root `.htaccess` rewrites every URL
to `index.php`, which is correct on Apache. If `.htaccess` isn't honored, all of these become downloadable:

- `.git/`, which contains the source and the history with old secrets;
- `config/app.php`, wherever PHP isn't executed;
- `storage/logs`, `storage/backups` and `storage/uploads`;
- the QA review files.

That happens with nginx, `AllowOverride None`, or some LiteSpeed setups. Separately, `Options -Indexes` returns 500 on
hosts that disallow `Options` in `.htaccess`.

**Fix:** Recommend `public/` as the document root first. Otherwise deploy an artifact rather than a Git checkout, and
add a deploy check that requests `/.git/HEAD`, `/config/app.php` and `/storage/logs/` and expects 403 or 404 for each.

Cross-reference: Codex D01, Cursor C11.

## Config startup checks — `config/app.php:188-221`

- The JWT secret check counts characters, not bytes (FW-17).
- `cors.origins` must be non-empty even when `cors.enabled` is false.
- Nothing checks that `trusted_proxies` entries are valid IPs, or that `timezone` is a valid zone. An invalid zone
  makes `RequestLogger` silently stop logging, because its `DateTimeZone` exception is swallowed.
- The production checks key off `env === 'production'` only. A deploy that leaves `env` as `'development'` skips all of
  them. Consider failing when `debug` is true and the request host isn't `localhost`.

## CLI — `cli/*`, `src/Core/MakeCommand.php`

- All three scripts exit unless `PHP_SAPI` is `cli`, and `cli/` is blocked by `.htaccess`. Both are correct.
- `cli/migrate.php`: rollback guard and locking (FW-21).
- `MakeCommand` accepts names that produce invalid PHP, such as reserved words like `class`. (Codex F31)
- There's no CLI command to create the first administrator. Add one, such as `cli/user`, so apps don't need a public
  bootstrap path.

## Performance summary

I ran no load test. The figures are reasoned from the code.

| Fixed cost per request | Source | Notes |
|---|---|---|
| About 15 autoloaded files plus `config/app.php` | bootstrap | Needs OPcache with `validate_timestamps=0`, or the PHP is parsed on every request |
| Exclusive file lock, read and write | rate limiter | The largest fixed cost, and serialized per IP (FW-03) |
| Route regex rebuild × N routes | router | Small (FW-12) |
| Two JSON decodes of the body | request | FW-11 |
| Locked append to the daily log | logger | Runs after `fastcgi_finish_request`, but still occupies an FPM worker |

| Scaling risk | Source |
|---|---|
| O(N²) wildcard validation and N queries for `exists`/`unique` | FW-22 |
| Whole-file log reads | FW-24 |
| `OFFSET` backup paging | FW-20 |
| Unbounded `all()`/`where()` | FW-18 |
| No database timeout | FW-19 |

## Controls that held

- **SQL:** identifiers are allowlisted (`Model::identifier`, `Validator::safeIdentifier`,
  `DatabaseBackup::quoteName`). Values are always bound, emulated prepares are off, and `LIMIT`/`OFFSET` are bound as
  integers.
- **JWT:**
  - the algorithm is pinned to HS256, so `alg=none` and algorithm confusion are rejected;
  - the signature is compared in constant time with `hash_equals`;
  - `exp`, `nbf`, `iat`, `iss` and `aud` are all checked;
  - Base64URL decoding is strict;
  - a random `jti` is generated;
  - a minimum secret length is enforced.
- **Passwords:** bcrypt with a configurable cost, transparent rehashing, and support for the 72-byte limit.
- **Uploads:** `is_uploaded_file` plus `move_uploaded_file`, stored names from `random_bytes`, sanitized original
  names, MIME type detected with `finfo` and matched to the extension, and deletion confined to the upload root with
  `realpath`.
- **Views:** path containment, reserved names, and buffer safety.
- **CORS:** explicit origins, matching with `hash_equals`, `Vary: Origin`, and no wildcard when credentials are
  allowed.
- **Responses:** `nosniff`, `DENY`, `no-referrer`, `no-store`, a Permissions-Policy, a sanitized
  `Content-Disposition`, and protection against header injection from PHP's `header()`.
- **Validation:** rule definitions are checked before any input. Unknown fields are rejected recursively. Integers are
  checked for overflow, dates strictly, and regex and identifier parameters are checked.
- **Autoloader:** limited to two prefixes and requires `is_file`.
- **Rate limiter:** files are named by a `sha256` of the IP, so a crafted IP can't cause path traversal.

## Suggested fix order

1. **Request and identity:** FW-01, FW-02.
2. **Operations:** FW-04 (error logging and display), FW-14 (UTC everywhere), FW-23 (logger user, redaction and
   retention).
3. **Response contract:** FW-07, FW-08, FW-09, FW-13. Mobile code will depend on these, so fix them before the first
   app release.
4. **Auth and rate limiting:** FW-05 (sessions with refresh tokens), FW-03 (keyed limiter with cleanup), FW-15 (force
   HTTPS).
5. **Data layer:** FW-18 (boolean binding and hidden columns), FW-19 (timeout and transaction helper), FW-21
   (migration lock and rollback guard).
6. **Remaining items:** FW-10, FW-11, FW-12, FW-16, FW-17, FW-20, FW-22, FW-24, FW-25, then FW-06 in the deployment
   docs.

The repository has no automated tests. A small PHP test runner with no dependencies would lock these fixes in. The
harness calls used for this review (`Application::dispatch()` with hand-built `Request` objects) show that the core can
already be tested without a web server.

## Verification performed

- **Lint:** `php -l` on every PHP file in `app cli src config migrations public routes`, plus `cli/make` and
  `cli/rate-limit`, on PHP 8.4.4. No errors.
- **Dispatch harness:** `Application::dispatch()` with hand-built `Request` objects, with the limiter and logger
  disabled. It confirmed FW-01, FW-02, FW-07, FW-12 (empty permission list, `+` decoding) and FW-23 (user lost).
- **Response harness:** confirmed FW-08 (stream emitted after `error()`), FW-09 (`text/html` served inline), and FW-13
  (`JsonException` on invalid UTF-8, merged `Set-Cookie`).
- **Runtime checks:** `PDOException` is a subclass of `RuntimeException`, and the default PHP time zone is `UTC`.
- **JWT:** a token signed with the published secret was accepted by `JWT::decode()`. That's why the secret must be
  rotated (see [Scope](#scope)).
- **Not executed:**
  - **MySQL strict-mode binding (FW-18).** The local server rejected the tracked credentials.
  - **Apache running without `.htaccess` (FW-06).**
  - **Behavior when a client disconnects mid-stream (FW-20).**
  - **Load testing.**

## File coverage (framework core)

| File | Findings |
|---|---|
| `index.php`, `public/index.php` | FW-04 |
| `.htaccess`, `public/.htaccess` | FW-06 |
| `config/app.php` (startup checks) | FW-17, config checks |
| `src/bootstrap.php`, `src/helpers.php` | Sound |
| `src/Core/Application.php` | FW-04, FW-14, FW-15, FW-16, FW-23 |
| `src/Core/Debugger.php` | Sound; array `message` (FW-07) |
| `src/Core/MakeCommand.php` | CLI notes |
| `src/Http/Request.php` | FW-01, FW-02, FW-11 |
| `src/Http/Response.php` | FW-07, FW-08, FW-09, FW-13 |
| `src/Http/CORS.php` | Sound; FW-13 note |
| `src/Http/Pagination.php`, `HttpException.php` | Sound |
| `src/Routing/Router.php`, `Route.php` | FW-12, FW-23 |
| `src/Auth/JWT.php`, `AccessToken.php`, `Password.php`, `JWTException.php` | FW-05, FW-17 |
| `src/RateLimit/RateLimiter.php`, `RateLimitDecision.php` | FW-03 |
| `src/Database/Database.php` | FW-14, FW-19 |
| `src/Database/Model.php` | FW-18 |
| `src/Database/DatabaseBackup.php` | FW-20 |
| `src/Database/Migrations/Migrator.php`, `Migration.php` | FW-21 |
| `src/Validation/Validator.php`, `ValidationException.php` | FW-07, FW-11, FW-22 |
| `src/Logging/RequestLogger.php` | FW-01, FW-23, FW-24 |
| `src/Mail/SMTPMailer.php`, `MailException.php` | FW-25 |
| `src/Storage/FileUploader.php`, `FileUploadException.php` | FW-10 |
| `src/View/View.php` | Sound |
| `cli/make`, `cli/migrate.php`, `cli/rate-limit` | FW-21, CLI notes |
| `app/**`, `routes/api.php`, `migrations/*` | Example code: cited as evidence only, not reviewed as endpoints |
