# Security model and production checklist

## What the code does

| Area | Control | Where |
|---|---|---|
| Passwords | Argon2id; policy (length, letters+digits, not email name, not common); rehash on login | `Auth/PasswordPolicy`, `Auth/AuthService` |
| Brute force | 5 failures / 15 min per email+IP, 30 per IP; 2FA attempts limited; route throttles on login/reset/register | `AuthService`, `Http/Middleware/Throttle` |
| 2FA | TOTP (RFC 6238) with encrypted secret, 8 hashed single-use recovery codes | `Auth/Totp`, `SecurityController` |
| Sessions | HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode, ID regenerated on login; idle & absolute timeouts; "sign out everywhere"; role change or suspension ends sessions | `Core/Session`, `AuthService::user()` |
| CSRF | Per-session token required on every non-GET web request | `Middleware/VerifyCsrf` |
| XSS | All template output through `e()`; strict CSP (no inline script/style); JS inserts text via `textContent`-escaped HTML | views, `App::securityHeaders` |
| SQL injection | PDO prepared statements, emulation off; identifiers validated; LIKE wildcards escaped | `Core/Database` |
| Tenant isolation | Tenant id only from session/token/webhook account; `TenantRepository` adds `tenant_id` to every query; cross-tenant ids return 404 | `Tenancy/*`, tests in `TenantIsolationTest` |
| Authorization | Granular permissions per role, checked in routes and services; owner role protected | `Rbac/*`, `Middleware/Can` |
| Secrets at rest | Provider credentials and TOTP secrets encrypted (sodium secretbox, or AES-256-GCM via OpenSSL when sodium is missing; APP_KEY); API tokens stored as SHA-256 | `Core/Crypto`, `Auth/ApiTokenService` |
| Webhooks | Unguessable per-account URL; Meta HMAC signature (constant-time); phone-number-id check; 1 MB limit; dedupe table; async processing | `WebhookController`, `ProcessWebhookJob` |
| API | Bearer tokens with expiry & revocation; per-token rate limit; idempotency keys; JSON errors with request id | `Middleware/ApiAuth`, `Api/*` |
| Headers | CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, COOP, HSTS (when forced HTTPS), no-store on HTML | `App::securityHeaders` |
| Logging | JSON logs with secrets redacted; message bodies are not logged | `Core/Logger` |
| Audit | Logins, 2FA changes, user/role/team changes, credential rotation, exports, tenant suspension | `audit_logs` |
| Redirects | Only local paths accepted | `Response::redirect` |
| CSV export | Formula-injection neutralised | `ContactService::csvSafe` |
| Facebook connect | Code exchanged server-side within 30 s; number ownership verified against the token's WABA; tokens and app secret encrypted; Facebook SDK allowed only on the connect page (CSP + `same-origin-allow-popups`); platform webhook HMAC-verified; one company per number | `EmbeddedSignupService`, `MetaConnectController` |
| Installer | Remote visitors need the key from `storage/install-key.txt`; refuses sub-folder installs; verifies `.env`/source can't be downloaded when the app sits inside the web folder; locks itself | `Install/*` |
| Web cron | Disabled unless `CRON_KEY` (≥16 chars) is set; constant-time comparison; throttled; one runner at a time (file lock) | `CronController`, `BackgroundRunner` |
| Super admin | Separate area, no tenant data access to message contents | `Admin/AdminController` |

## Before going live

- [ ] `APP_DEBUG=false`, `APP_FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`
- [ ] `APP_KEY` generated, backed up offline, `.env` mode 600 and owned by the web user
- [ ] Only `public/` is the web root; `location ~ \.php$ { return 404; }` except `index.php`
- [ ] `TRUSTED_PROXIES` set only if behind a reverse proxy/load balancer
- [ ] Super admin and every company owner have 2FA enabled
- [ ] `ALLOW_REGISTRATION=false` unless you want public sign-ups
- [ ] Database user has only the privileges listed in DEPLOYMENT.md; DB not reachable from the internet
- [ ] Firewall: only 22/80/443 open; OpenWA gateway (if used) on a private network only
- [ ] Backups scheduled and a restore tested
- [ ] Log rotation for `storage/logs/*.log` (logrotate; on shared hosting, delete old logs from the File Manager now and then)
- [ ] Shared hosting: app folder outside `public_html` (layouts A/B in SHARED_HOSTING.md), `.env` at 640, `CRON_KEY` kept private
- [ ] Retention values set (`AUDIT_RETENTION_DAYS`, `MESSAGE_RETENTION_DAYS`) to match your privacy policy

## Known gaps (tracked in FEATURES.md)

- No built-in SMTP client yet (uses the server's `sendmail` via `MAIL_DRIVER=mail`).
- Super-admin 2FA is strongly recommended but not forced.
- Data-subject export/deletion is manual today (contact archive + SQL); a self-service workflow is planned.
- Rate limiting is database-backed; a Redis driver is planned for high volume.
