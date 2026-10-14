# ExpressPHP security fixes (Claude)

Fixes for every finding in [CLAUDE_SECURITY_REVIEW.md](CLAUDE_SECURITY_REVIEW.md), S-00 to S-08, plus its process note.
Applied 2026-10-14 on top of commit `7b3a893`.

## Summary

| ID | Finding | Fix |
|---|---|---|
| S-00 | Git stored `IpAddress.php`; autoloading fails on Linux | Renamed in Git through a temporary name |
| S-01 | Accented or differently cased usernames bypassed the login limit | Count failures by account id |
| S-02 | A long User-Agent could make login fail with 500 | New `UTF8` helper repairs and safely shortens stored text |
| S-03 | Anyone could lock any account out of login | Count per account and client network, plus a higher account-wide limit |
| S-04 | IPv6 clients could escape a manual block within their /64 | Blocks apply to the client network |
| S-05 | Session cleanup scanned the whole table at every login | Cleanup uses the indexed condition only |
| S-06 | Deprecated `VALUES()` in the session upsert | Delete and insert in one transaction, with one retry |
| S-07 | `Response::error()` could break the error shape | Errors are normalized to `{field: [messages]}` |
| S-08 | A real field named `_request` would mix with request-level errors | `_request` is reserved in validation rules |
| Note | `AGENTS.md` contradicted the docblock style | Rule updated |

## Behavior changes

- **Login limits:**
  - Failed logins are counted per account and client network: 5 per 15 minutes.
  - They are also counted per account across all networks: 50 per hour.
  - Unknown usernames are counted per client network.
  - Previously, failures were counted per spelled username only.
- **IPv6 blocks:** blocking an IPv6 address now blocks its whole /64 network.
- **Error responses:** `Response::error()` now converts a plain list of messages to `{"_request": [...]}`, and
  converts any message that isn't a string.
- **Validation rules:** a field named `_request` is now refused when the rules are defined.
- **New config keys:** `rate_limiter.login_account_max_attempts` (50) and
  `rate_limiter.login_account_window_seconds` (3600).

## Fixes by finding

### S-00 — File name case in Git

**Fix:** ran a two-step rename through a temporary name, which records the change even though Git on this Mac ignores
upper/lower case (`core.ignorecase = true`):

```bash
git mv src/Http/IpAddress.php src/Http/IPAddress.tmp
git mv src/Http/IPAddress.tmp src/Http/IPAddress.php
```

**Result:** `git ls-files` now lists `src/Http/IPAddress.php`, and `git status` shows the rename as staged.

**Check after committing:** `git ls-files src/Http | grep -i ipaddress` must print `src/Http/IPAddress.php`.

### S-01 — Username variants bypassed the login limit

**Fix:** in `app/Controllers/AuthController.php`, the new `loginLimits()` looks the account up with
`User::findByUsername()`. That lookup uses the same database comparison as login, so `akbar`, `ÁKBÁR` and `ákbar` all
resolve to the same account id. The counters are keyed by that id (`login:user:<id>…`), so every spelling of one
account shares its count.

**Verified** in a scratch MySQL database: once an account was limited, `OWNER`, `ówner`, `öwnér` and `ÒWNÈR` all
returned 429. A variant with the correct password was also limited.

### S-02 — A long User-Agent could make login fail with 500

**Fix:**

- `src/Support/UTF8.php` (new):
  - `scrub()` replaces invalid UTF-8 with U+FFFD, using the always-available JSON encoder rather than mbstring;
  - `truncate()` shortens text to a byte limit without splitting a character.
- `app/Models/UserSession.php`: the session's `user_agent` is stored with `UTF8::truncate(..., 500)`.
- `app/Models/ActivityLog.php`: the activity log's `user_agent` had no limit at all. It is now stored the same way.
- `app/Controllers/EmailController.php`: SMTP error text, which can contain arbitrary bytes from the mail server, is
  stored with `UTF8::truncate(..., 2000)`.

**Verified:** login with a 500-byte User-Agent ending in `é` returned 200 and stored 499 bytes of valid UTF-8. Before
the fix it returned 500.

### S-03 — Anyone could lock any account out of login

**Fix:** `loginLimits()` returns two counters for an existing account. A failed login counts against both, and either
one can refuse the next attempt:

| Counter | Default | Purpose |
|---|---|---|
| `login:user:<id>:client:<network>` | 5 per 15 minutes | Stops guessing from one network; an attacker locks out only their own network |
| `login:user:<id>` | 50 per hour | Stops guessing spread across many networks |

Unknown usernames are counted under `login:client:<network>`. A successful login resets that client's counter. The
account-wide counter expires on its own window, so many failures from many networks can't be erased by one success.

`config/app.php` adds `login_account_max_attempts` and `login_account_window_seconds`.

**Verified:**

- After one network was limited, the owner logged in from another network (200).
- Failures from a third network reached the account-wide limit (set to 8 for the test), and a fourth, fresh network
  then got 429.
- Six unknown usernames from one network returned five 401 responses, then 429.

### S-04 — IPv6 clients could escape a manual block

**Fix:** in `src/RateLimit/RateLimiter.php`:

- `block()` stores the block under `clientKey()`, which is the /64 network for IPv6 and the address itself for IPv4.
  The record also keeps the `client` value.
- The new `isBlocked()` checks the network, and also the single address, so blocks written by the earlier layout still
  apply.
- `clear()` removes both forms.
- The old-layout migration writes network keys.
- IPv4 block files keep their existing names, so current IPv4 blocks are unaffected.

**Verified** in `tests/run.php`:

- blocking `2001:db8:5:6::1` also blocks `2001:db8:5:6:ffff::9`;
- `clear()` lifts the block;
- a block file in the old single-address format is still enforced.

### S-05 — Session cleanup scanned the whole table

**Fix:** `UserSession::deleteExpired()` now runs `DELETE … WHERE expires_at <= CURRENT_TIMESTAMP` only. `expires_at`
is always capped at `absolute_expires_at` by `start()` and `touch()`, so this single condition covers both limits.

**Verified:** `EXPLAIN` lists `idx_user_sessions_expires_at` as a usable index.

### S-06 — Deprecated `VALUES()` in the session upsert

**Fix:** `UserSession::start()` replaces the session in `Database::transaction()`, using the new private `replace()`:
it deletes the user's row, then inserts the new one. That works on both MySQL and MariaDB. Two simultaneous logins
for the same user can collide on the unique key (error 1062) or deadlock (error 1213), so the transaction is retried
once.

**Verified:** logging in again leaves exactly one session row for the user, and every earlier session test still passes.

### S-07 — `Response::error()` could break the error shape

**Fix:**

- `src/Validation/ValidationException.php`:
  - `normalize()` is now public, and `Response::error()` runs its errors through it;
  - a list of messages becomes `_request` errors;
  - a list whose values are lists is treated as a field map, which keeps fields named `"0"` working;
  - the new `message()` converts non-string messages with `json_encode` instead of producing `Array`.

**Verified:** `error('x', 400, ['Name is required.'])` returns `{"_request": ["Name is required."]}`, and
`['name' => 'Required.']` returns `{"name": ["Required."]}`.

### S-08 — `_request` could collide with a real field

**Fix:** `src/Validation/Validator.php` `assertFieldPath()` refuses any rule whose field path starts with `_request`,
with an error raised when the rules are defined.

**Verified:** `Validator::validate([], ['_request' => 'optional|string'])` throws.

### Process note — the comment rule

**Fix:** `AGENTS.md` now says: "Give every function a docblock describing what it does; add inline comments only for
non-obvious decisions."

**Result:** all 334 functions in scope have a docblock, including the four added in this change: `UTF8::scrub()`,
`UTF8::truncate()`, `UserSession::replace()` and `AuthController::loginLimits()`.

## Documentation

- `README.md`: the login limits, the account-wide limit, and IPv6 network blocks.
- `PROJECT.md`: added `src/Support/UTF8.php`.

## Verification

- **Tests:** `php tests/run.php` passes 41 of 41 on PHP 8.2.20, 8.3.9 and 8.4.4. Four tests were added:
  - error-list normalization;
  - the reserved `_request` field;
  - IPv6 network blocks with old-layout blocks;
  - UTF-8 repair and truncation.
- **End to end on MySQL 8.0.35:** 36 checks pass in a scratch `expressphp_test` database, created and dropped by the
  test. Nine checks cover S-01, S-02, S-03, S-05 and S-06, and the rest are the earlier session, backup and database
  checks.
- **Lint:** clean on PHP 8.2.
- **Docblocks:** 0 of 334 functions in scope lack one.
- **Live requests through MAMP Apache:** the database health check returned 200, and a login without a password
  returned the 422 error shape.
- **Your database:** `expressphp_db` was not touched.
