# ExpressPHP security review (Claude)

Review date: 2026-10-14. Reviewer: Claude Opus 5.5. Code reviewed: commit `7b3a893` on `main`.

## Scope

Every file outside `app/`, `routes/` and `storage/`:

- `src/`;
- `cli/`;
- `config/app.php`;
- the entry points (`index.php`, `public/index.php`) and both `.htaccess` files;
- `migrations/`;
- `tests/`.

The new login and session code in `app/` was also reviewed, because it is security-critical.

**Status:** every finding below is fixed; see [CLAUDE_SECURITY_FIXES.md](CLAUDE_SECURITY_FIXES.md). The findings describe the
code as it was reviewed.

## Summary

| ID | Finding | Severity | Evidence |
|---|---|---|---|
| S-00 | Git stores `IpAddress.php`, but the class is `IPAddress`, so autoloading fails on Linux | High | Verified |
| S-01 | The per-username login limit can be bypassed with accented or differently cased usernames | High | Verified |
| S-02 | A long User-Agent can make login fail with 500 | Medium | Verified |
| S-03 | Anyone can lock any account out of login | Medium | Code |
| S-04 | Manual IP blocks can be evaded within the same IPv6 /64 | Low | Code |
| S-05 | Expired-session cleanup scans the whole table at every login | Low | Code |
| S-06 | `ON DUPLICATE KEY UPDATE … VALUES()` is deprecated in MySQL | Low | Code |
| S-07 | `Response::error()` can break the stable error shape | Low | Code |
| S-08 | A real field named `_request` would mix with request-level errors | Low | Code |

**Evidence tags:**

- **Verified:** reproduced on PHP 8.3.9 and MySQL 8.0.35 in a scratch database, `expressphp_test`, which was dropped
  afterwards.
- **Code:** traced in the source.

---

## High

### S-00 — The file name case is wrong in Git

**Where:** `src/Http/IPAddress.php`. Git tracks it as `src/Http/IpAddress.php`.

**What:** The class is `ExpressPHP\Http\IPAddress`, so the autoloader looks for `src/Http/IPAddress.php`. On macOS,
the file system and Git (`core.ignorecase = true`) ignore case, so everything works locally. On Linux, the usual
production system, the class isn't found. Any request that checks trusted proxies, and the `trusted_proxies` check in
`config/app.php`, then fail with a fatal error.

Commits `e0af56d`, `1ea64d5` and `7b3a893` all still store the old name. A one-step `git mv` that only changes case
doesn't stick on this machine.

**Fix:** rename through a temporary name, so Git records a real rename:

```bash
git mv src/Http/IpAddress.php src/Http/IPAddress.tmp
git mv src/Http/IPAddress.tmp src/Http/IPAddress.php
git commit -m "fix: store IPAddress.php with the correct case"
git ls-files src/Http | grep -i ipaddress   # must print src/Http/IPAddress.php
```

### S-01 — The login limit can be bypassed with username variants

**Where:** `app/Controllers/AuthController.php:64` builds the limiter key as
`'login:' . strtolower(trim($username))`. The `users` table uses `utf8mb4_unicode_ci`
(`migrations/2026_08_25_000001_init_project.php`).

**What:** `utf8mb4_unicode_ci` ignores accents as well as case, so the database treats `akbar`, `ÁKBÁR`, `ákbar`
and `akbär` as the same username. The limiter key only lowercases ASCII, so each spelling gets its own counter.

**Verified:**

- `ÁKBÁR` with the right password logged in as `akbar` (200).
- Six wrong passwords for `akbar` returned `401, 401, 401, 401, 401, 429`, so the account was limited.
- `ákbar`, `àkbar`, `âkbar`, `äkbar`, `akbár` and `akbàr` each then returned 401, not 429. Every variant gets five
  fresh attempts.

Every letter has several accented forms, so a username has thousands of variants. The 5-attempts-per-15-minutes limit
doesn't protect the account against password guessing.

**Fix:**

1. Look up the account first, using the same database comparison as login, and build the key from the user id:
   `login:user:<id>`. Every spelling of one account then shares one counter.
2. For usernames that don't exist, count per client instead, with the key `login:client:<client key>`.

### Medium

### S-02 — A long User-Agent can make login fail with 500

**Where:** `app/Models/UserSession.php:55` stores `substr($userAgent, 0, 500)`.

**What:** `substr()` cuts at 500 *bytes*. If byte 500 falls inside a multi-byte character such as `é`, the stored value
ends with half a character. MySQL in strict mode rejects it with error 1366 (`Incorrect string value: '\xC3'`), so
login fails with 500 for that client. A User-Agent that already contains invalid UTF-8 fails the same way.

**Verified:** a 500-byte User-Agent ending in `é` made login return 500, and the error log recorded the 1366 error.

`app/Models/ActivityLog.php:66` stores the raw User-Agent with no length limit at all. The same problem applies there,
plus `Data too long` errors for values over 500 bytes.

**Fix:**
- Add a framework helper that replaces invalid UTF-8 and cuts on a character boundary.
- Use it for every client-supplied header that is stored: `user_agent` in sessions and in activity logs.

### S-03 — Anyone can lock any account out of login

**Where:** `app/Controllers/AuthController.php` login limit (`login_max_attempts` 5, `login_window_seconds` 900).

**What:** The limit counts failures per username only. Anyone who knows or guesses a username can send five wrong
passwords and block that account for 15 minutes, and repeat that forever. The real user can't log in, and because
of S-01, from any IP.

**Fix:** keep an account-wide limit, but count failures per account *and* client:

- `login:user:<id>:<client>` with the current small limit;
- `login:user:<id>` with a higher limit, for example 50 per hour, against distributed guessing.

An attacker from one network then only locks themselves out.

## Low

### S-04 — Manual IP blocks can be evaded within the same IPv6 /64

**Where:** `src/RateLimit/RateLimiter.php:74`, `:168` and `:189`. Blocks are stored and checked per exact address.

**What:** The request limits already treat an IPv6 /64 as one client, but `block()` and the check in `check()` use the
single address. A blocked IPv6 client can switch to another address in the same /64.

**Fix:** store and check blocks by `clientKey()` (the /64) for IPv6, and keep single addresses for IPv4.

### S-05 — Expired-session cleanup scans the whole table

**Where:** `app/Models/UserSession.php:121`, which runs
`DELETE … WHERE expires_at <= NOW() OR absolute_expires_at <= NOW()` at every login.

**What:** The `OR` on an unindexed column prevents MySQL from using `idx_user_sessions_expires_at`, so every login
scans every session row. `expires_at` is never later than `absolute_expires_at`, because `start()` and `touch()` both
cap it, so the second condition never matters.

**Fix:** delete with `WHERE expires_at <= CURRENT_TIMESTAMP` only.

### S-06 — Deprecated `VALUES()` in `ON DUPLICATE KEY UPDATE`

**Where:** `app/Models/UserSession.php:46`.

**What:** MySQL 8.0.20 deprecated `VALUES(column)` in `ON DUPLICATE KEY UPDATE`. It still works, but it will stop
working in a future MySQL version. The row-alias replacement (`… AS new ON DUPLICATE KEY UPDATE token_hash =
new.token_hash`) needs MySQL 8.0.19 or later and isn't supported by MariaDB.

**Fix:** replace the session inside `Database::transaction()`, with `DELETE … WHERE user_id = ?` followed by `INSERT`,
which works on both servers. Alternatively, repeat the bound parameters in the `UPDATE` part.

### S-07 — `Response::error()` can break the stable error shape

**Where:** `src/Http/Response.php:108-118`.

**What:** The documented shape is `errors: {field: [messages]}`. `error()` sends whatever array it receives. A plain
list such as `['Name is required.']` produces `"errors": {"0": "Name is required."}`, where the value is a string
instead of a list, and typed decoders in the mobile app fail.

**Fix:** normalize `$errors` the same way `ValidationException` does: a plain list goes under `_request`, and each
value is converted to a list of strings.

### S-08 — Request-level errors use a key that a real field could have

**Where:** `src/Validation/ValidationException.php:13` (`REQUEST_FIELD = '_request'`).

**What:** Request-level errors, such as "a body is not allowed for GET", use the `_request` key. A form field actually
named `_request` would have its errors merged with them. This is very unlikely in practice.

**Fix:** document `_request` as reserved, or reject `_request` as a field name in validation rules.

---

## Process note

`AGENTS.md:39` says "Add comments only for non-obvious decisions", while the codebase now has a docblock on every
function. Change that line to "Every function has a docblock describing what it does; add inline comments only for
non-obvious decisions", so future changes follow one rule.

## Controls that still hold

Everything checked in earlier rounds still holds:

- **Sessions:** login, one session per device, hash-only token storage, the 7-day sliding and 90-day absolute expiry,
  and session end on logout, password change and deactivation.
- **Request handling:** the stable 422 shape, the request body limit, query/body separation, and the trusted-proxy
  rules.
- **Logging and storage:** redaction, file modes, and upload validation.
- **Database:** migration locks, the rollback `--force` guard, backup snapshot and restore, the UTC session, and the
  statement timeout.

These are covered by `tests/run.php` (37 of 37 pass on PHP 8.2, 8.3 and 8.4) and by a 28-check end-to-end run on
MySQL 8.0.35.

## Suggested order

1. **S-00 now.** Without it the API doesn't run on Linux.
2. **S-01 to S-03 together.** They all concern the login limiter and change the same code.
3. **S-04 to S-08**, and the `AGENTS.md` line.
