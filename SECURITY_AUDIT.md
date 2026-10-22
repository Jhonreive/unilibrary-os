# UniLibrary OS - Security Audit & Hardening Report

Scope: every PHP page, includes, SQL files, JS and config in this project.
Method: manual code review against OWASP Top 10, then every fix was exercised
against a live copy (PHP 8.3 + MariaDB) with attack simulations (CSRF, forged
Origin, XSS payloads, SQL-injection strings, brute force, privilege escalation,
session replay, idle timeout, DB outage, Host-header spoofing).

## 1. Gaps found in the original code

| # | Severity | Gap (OWASP) | Where |
|---|----------|-------------|-------|
| 1 | High | **Broken access control** - any logged-in Staff account could add/edit/delete books and members, and view/export all reports (only `users.php` was role-gated). Role was only used to hide one menu item. (A01) | books, members, reports, export_reports |
| 2 | High | **Hardcoded DB credentials** (`root`, empty password) in source; `$DEBUG = true` printed raw DB errors. (A02, A05) | includes/db.php |
| 3 | High | **Debug/leak endpoints**: `info.php` exposed php.ini path + DB drivers; empty `hash.php` was a leftover password-hash tool; `setup.php` was open to the whole internet. (A05) | info.php, hash.php, setup.php |
| 4 | High | **Weak brute-force protection**: throttle lived in the *session*, so deleting the cookie reset it. None on sign-up or password change. (A07) | auth.php, login/signup |
| 5 | Medium | **Deactivated/demoted users kept working**: session only checked that an ID existed; suspending a user did not end their session; changing a password did not log other devices out. No idle/absolute timeout. (A07) | auth.php |
| 6 | Medium | **Password policy = 8 chars, nothing else**; bcrypt cost 10 via `PASSWORD_DEFAULT`. (A07, A02) | signup, settings, users, setup |
| 7 | Medium | **No security headers** (no CSP, X-Frame-Options, nosniff, HSTS), pages were cacheable, inline `onchange=` handlers. (A05) | all pages |
| 8 | Medium | Cookie `SameSite=Lax`, `Secure` only if HTTPS happened to be detected. (A07) | auth.php |
| 9 | Medium | Raw `$_POST/$_GET` values reached `trim()`/casts: array inputs (`?q[]=x`) caused TypeErrors/500s; no length/format limits (name, email, ISBN, school ID, dates). (A03, A04) | all pages |
| 10 | Low | Errors: uncaught exceptions could print stack traces; generic only in some catch blocks. (A05) | global |
| 11 | Low | CSRF relied on the token alone; no Origin check. (A01) | auth.php |
| 12 | Low | No audit trail for logins, role changes, resets. (A09) | - |

Already good (kept): all SQL used PDO prepared statements; output escaped with
`e()`; CSRF tokens on every POST form; session id regenerated on login;
HttpOnly cookie; generic "Incorrect email or password"; last-admin protection;
CSV formula-injection guard; open-redirect check.

## 2. What changed

**Data & database**
- Real server-side prepared statements (`PDO::ATTR_EMULATE_PREPARES=false`), stacked queries disabled. No user input is concatenated into SQL anywhere (the dynamic filters append only fixed fragments with `?` placeholders; the two `LIMIT` values are now fixed literals).
- Passwords: **Argon2id** (19 MiB, t=2) with bcrypt cost-12 fallback. Old hashes are upgraded silently at next login. Passwords are never logged or echoed.

**Authentication & sessions**
- Cookie: `HttpOnly`, `Secure` (forced in production), `SameSite=Strict`, `__Host-` prefix when secure, strict mode, no URL session ids.
- 30-min idle timeout + 8-hour absolute lifetime (configurable). New session id + new CSRF token at login.
- Session is re-validated against the DB on **every** request: deleted, suspended or demoted accounts are cut off immediately; **changing/resetting a password ends all other sessions** of that user.
- Rate limits (stored in the database, per IP / IP+account / account): login 5 failures per 15 min per IP+account, 30 per IP, 20 per account; sign-up 5/hour/IP; password change 5/15 min; admin password reset 10/15 min; setup.php 10/15 min. Responds 429 + `Retry-After`. Constant-time-ish login (dummy hash for unknown emails).
- Password policy: 10-128 chars, at least 3 of 4 character classes, blocks common passwords and anything containing the user's name/email-name.

**Access control (server-side RBAC)**
- Permission matrix in `includes/auth.php` (`ROLE_PERMISSIONS`), enforced with `require_perm()` on every page *and inside every POST action*; the UI hides controls too, but never relied on:

| Permission | Administrator | Librarian | Staff |
|---|:-:|:-:|:-:|
| Browse books/members/history/search, borrow & return | yes | yes | yes |
| Add/edit/delete books (`catalog.manage`) | yes | yes | no |
| Add/edit/delete members (`members.manage`) | yes | yes | no |
| Reports + Excel export (`reports.view`) | yes | yes | no |
| Users admin, library settings | yes | no | no |

- IDOR: this app has no per-user-owned records; the only user-scoped resource (own profile/password) is bound to the **session's** user id, never a posted id. All admin actions that take an `id` require `users.manage`. Every id is parsed with `post_int()` and looked up before use.

**Input, XSS, CSRF**
- `post_str/get_str/post_int/post_raw` helpers: type-safe (arrays/objects become empty/0), control characters stripped, invalid UTF-8 rejected, length-capped. Dedicated validators for email, names, School ID, ISBN, dates (real calendar date, <= 1 year), copies (1-100000), enum whitelists for role/type/status/filters.
- Output encoding with `htmlspecialchars` everywhere; verified a `<script>` title renders inert. **CSP** forbids inline script unless it carries the per-request nonce (inline `onchange=` handlers were replaced by `data-autosubmit` in `script.js`).
- CSRF: synchronizer token on all POSTs + `Origin` / `Sec-Fetch-Site` checks + SameSite=Strict.

**Secrets & config**
- `includes/env.php` loads `.env`; `db.php` and all settings read from it. No credentials in code. `.gitignore` excludes `.env`; `.env.example` is the sanitized template. Real server env vars override the file; `ULIB_ENV_FILE` lets production keep it outside the web root.
- `.htaccess` blocks `.env`, `.sql`, `.md`, dotfiles, directory listing; `includes/*.php` cannot be requested directly.
- Removed `info.php` and `hash.php`. `setup.php` is dead (404) unless `ENABLE_SETUP=1` **and** the request comes from localhost.

**Headers, CORS, errors**
- CSP (`default-src 'self'`, nonce scripts, `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`, `upgrade-insecure-requests` in prod), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, HSTS (over HTTPS), Referrer-Policy, Permissions-Policy, COOP/CORP, `Cache-Control: no-store`, `X-Powered-By` removed.
- CORS: same-origin by default; cross-origin only for exact origins in `CORS_ALLOWED_ORIGINS`, never `*`; unknown-origin preflights get 403. Optional `APP_ALLOWED_HOSTS` blocks Host-header attacks.
- Production errors: generic page + short reference id; real exception, SQL and stack trace go only to the server log. `display_errors` is off unless `APP_ENV=development` and `APP_DEBUG=true`.
- Audit log lines (`[ulibrary][audit] ...`) for login ok/failed/throttled, access denied, CSRF rejects, role changes, activations, resets, loans.

## 3. Files

New: `includes/env.php`, `includes/security.php`, `.env`, `.env.example`, `.gitignore`, `.htaccess`, `includes/.htaccess`, `migrate_security.sql`, this report.
Rewritten: `includes/auth.php`, `includes/db.php`.
Modified: `login.php`, `signup.php`, `setup.php`, `users.php`, `settings.php`, `books.php`, `members.php`, `borrow.php`, `return.php`, `history.php`, `search.php`, `suggest.php`, `reports.php`, `export_reports.php`, `includes/header.php`, `includes/footer.php`, `script.js`, `schema.sql`.
Deleted: `info.php`, `hash.php`.

## 4. Do this when you deploy

1. `mysql -u root -p ulibrary < migrate_security.sql`, set a real password for the `ulibrary_app` DB user in that file first, then in `.env` use `DB_USER=ulibrary_app` (not root).
2. Production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_ALLOWED_HOSTS=<your domain>`, and serve over **HTTPS only** (production cookies are `Secure`, so plain HTTP logins will not stick, by design). Keep `.env` outside the web root and point `ULIB_ENV_FILE` at it.
3. If you're behind a reverse proxy, set `TRUSTED_PROXY=1`; otherwise leave it `0`.
4. Delete `setup.php` once every account has a password.
5. Everyone with an old password-less/short password must meet the new policy the next time they change it; existing sessions are signed out once after upgrading (expected).

Role changes to know about: **Staff** can no longer add/edit/delete books or members, or open Reports. Promote anyone who needs that to **Librarian** on the Users page.

## 5. Residual risks / recommendations (not changed)

- `style-src` still allows `'unsafe-inline'` (the templates use inline `<style>` and `style=` attributes). Script injection is blocked; moving CSS to files would let you drop it.
- Google Fonts are loaded from a third party; self-host the fonts for full CSP lock-down and privacy.
- LIKE searches treat `%`/`_` typed by the user as wildcards (no injection risk, only broader matches).
- The "last administrator" checks are not wrapped in a DB transaction (a race between two simultaneous admin actions is theoretically possible).
- No MFA and no email-based password reset (resets are admin-performed). Consider TOTP for Administrator accounts.
- Dependency/OS patching, DB backups and TLS configuration are outside the code; keep PHP and MySQL updated.
