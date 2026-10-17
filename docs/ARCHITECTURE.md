# WhatsApp CRM — Phase 1 Architecture

## 1. Stack decision

| Concern | Choice | Why |
|---|---|---|
| Language | PHP 8.3+ (strict_types everywhere) | Required by spec |
| Framework | **None — small in-house kernel** (`src/Core`) | Composer/Packagist was unavailable in the build environment; a zero-dependency codebase is also easier to audit and deploy on a plain VPS |
| Database | MySQL 8.0+ (production), SQLite 3.35+ (tests/dev) | Single DDL dialect translated per driver by `Core\Schema` |
| Queue | Database-backed queue (`jobs`, `failed_jobs`) + `bin/console queue:work` | No extra infrastructure; Redis driver is a planned drop-in (same `QueueInterface`) |
| Cache / rate limit | Database table `rate_limits` (Redis planned) | Works on any host |
| Frontend | Server-rendered PHP templates, vanilla JS, one CSS file, light/dark | No build step |
| Crypto | `sodium` (XSalsa20-Poly1305 secretbox) for stored credentials, `password_hash` Argon2id, HMAC-SHA256 for webhooks/API tokens | All in PHP core |
| Web server | Nginx + PHP-FPM, document root `public/` | |

Required PHP extensions: `pdo_mysql` (or `pdo_sqlite`), `sodium`, `curl`, `mbstring`, `json`, `openssl`, `fileinfo`.

## 2. Module map

```
public/index.php            front controller (only web-exposed PHP file)
bin/console                 CLI: migrate, seed, create-admin, queue:work, schedule:run
src/Core/                   kernel: Env, Config, Database, Schema, Migrator, Router, Request,
                            Response, Session, Csrf, View, Validator, Crypto, Logger,
                            RateLimiter, HttpClient, Queue, Container
src/Auth/                   AuthService, Totp (RFC 6238), PasswordPolicy, ApiTokenService
src/Tenancy/                TenantContext (current tenant), TenantRepository base class
src/Rbac/                   Permission catalogue, default roles, Gate
src/Audit/                  AuditLogger
src/Domain/Contacts/        PhoneNormalizer (E.164), ContactRepository, ContactService
src/Domain/Conversations/   ConversationRepository, MessageRepository, InboxService
src/WhatsApp/               ProviderInterface, Capability enum, MetaCloudProvider,
                            OpenWaProvider, ProviderFactory, NormalizedEvent,
                            WebhookIngestor, Jobs (SendMessage, ProcessWebhook)
src/Http/Controllers/       thin controllers (validate → call service → render)
src/Http/Middleware/        Auth, Guest, Tenant, Permission, SuperAdmin, Csrf, ApiAuth,
                            SecurityHeaders, Throttle
views/                      templates (auto-escaped through e())
database/migrations/        ordered PHP migration files
tests/                      zero-dependency test runner + unit/feature tests
```

Rule: controllers never run SQL. Tenant-owned data is only reachable through repositories that extend `TenantRepository`, which injects `tenant_id = :current_tenant` into every read/update/delete.

## 3. Tenant isolation model

* Every tenant-owned table has a non-null `tenant_id` FK + composite indexes beginning with `tenant_id`.
* `TenantContext` is set once per request by `TenantMiddleware` (from the logged-in user) or `ApiAuth` (from the token). It is immutable for the request; jobs carry `tenant_id` in their payload and re-establish the context.
* `TenantRepository::find($id)` = `WHERE id = ? AND tenant_id = ?`. A record from another tenant is indistinguishable from a missing one (HTTP 404, never 403, to avoid ID probing).
* Child records (messages, notes) are always loaded through a parent that was already tenant-checked, *and* carry their own `tenant_id`.
* Webhooks have no user: the tenant is resolved from the receiving `whatsapp_accounts` row (by per-account webhook URL token), never from payload content.
* Super admins operate in the `/admin` area with no `TenantContext`; they can manage tenants, plans and health but there is no admin route that reads `messages`.

## 4. Database relationships (core)

```
tenants 1─* users           tenants 1─* roles *─* permissions (role_permissions)
users *─1 roles             tenants 1─* teams *─* users (team_members)
tenants 1─* whatsapp_accounts (encrypted credentials, webhook_token, capabilities)
tenants 1─* contacts (phone_e164 UNIQUE per tenant, consent, opted_out_at)
contacts *─* tags (contact_tags)        contacts 1─* notes
contacts 1─* conversations *─1 whatsapp_accounts
conversations 1─* messages (UNIQUE(whatsapp_account_id, provider_message_id))
messages 1─* message_status_events
conversations 1─* conversation_assignments (history)
tenants 1─* api_tokens (sha256 hash only)
webhook_events (UNIQUE(provider, event_key)) — dedupe/replay log
audit_logs, login_attempts, jobs, failed_jobs, rate_limits, subscription_plans, subscriptions
```

Later phases add: leads, pipelines, pipeline_stages, deals, tasks, campaigns, campaign_recipients, campaign_events, message_templates, automation_workflows/versions/executions, chatbot_flows/versions/sessions, ai_* tables, webhook_endpoints/deliveries, integration_configs, usage_records, invoices, payment_transactions — all following the same `tenant_id` convention.

## 5. API structure (`/api/v1`)

Bearer token (`Authorization: Bearer wcrm_<id>_<secret>`), tenant derived from token. JSON only. Every response carries `X-Request-Id`. Errors: `{"error":{"code":"validation_failed","message":"…","details":{…}},"request_id":"…"}`. Pagination: `?page=&per_page=` (max 100) → `{"data":[…],"meta":{"page","per_page","total"}}`. Writes accept `Idempotency-Key`.

| Method | Path | Permission |
|---|---|---|
| GET | /api/v1/me | — |
| GET/POST | /api/v1/contacts | contacts.view / contacts.create |
| GET/PATCH/DELETE | /api/v1/contacts/{id} | contacts.view / .update / .delete |
| GET | /api/v1/conversations | inbox.view |
| GET | /api/v1/conversations/{id}/messages | inbox.view |
| POST | /api/v1/conversations/{id}/messages | inbox.reply |
| GET | /api/v1/whatsapp-accounts | whatsapp.view |
| GET/POST | /webhooks/whatsapp/{account_token} | public, signature-verified |

## 6. Provider compatibility matrix

| Capability | Meta Cloud API (official) | OpenWA-compatible gateway (unofficial, optional) |
|---|---|---|
| Text, image, document, audio, video | ✅ | ✅ |
| Approved templates | ✅ (required outside 24 h window) | ❌ |
| Interactive buttons / lists | ✅ | ⚠️ gateway-version dependent (off by default) |
| Reactions | ✅ | ✅ |
| Reply-to (context) | ✅ | ✅ |
| Message edit | ❌ (not exposed) | ⚠️ version dependent |
| Groups / participants / invite links | ❌ | ✅ |
| Read receipts / delivery status | ✅ via webhook | ✅ via webhook |
| Typing indicator | ❌ | ✅ |
| QR / pairing session | n/a | ✅ |
| Webhook signature | ✅ `X-Hub-Signature-256` (app secret) | ✅ HMAC header (shared secret configured on gateway) |
| Production suitability | Supported by Meta | **Risk:** unofficial WhatsApp Web automation can violate WhatsApp ToS and lead to number bans |

Capabilities live in code (`Capability` enum + each adapter's `capabilities()`), are stored per account, and the UI disables unsupported actions.

## 7. Security model

* **Auth:** Argon2id hashing; configurable password policy; session ID regenerated on login/2FA; idle + absolute session lifetime; "log out all devices" bumps `users.session_version`.
* **2FA:** TOTP (RFC 6238, SHA1, 6 digits, ±1 step), secret encrypted at rest, recovery codes hashed.
* **Brute force:** per-IP and per-email throttling on login and 2FA (`login_attempts`, `rate_limits`).
* **CSRF:** per-session token, required on every non-GET web request (constant-time compare).
* **XSS:** all template output via `e()`; strict CSP (`default-src 'self'`, no inline script); `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`.
* **SQLi:** PDO prepared statements only, emulation off; identifiers never come from user input.
* **Cookies:** `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS; HSTS when `APP_FORCE_HTTPS=true`.
* **Secrets:** `.env` only; provider credentials encrypted with `APP_KEY` (sodium secretbox); API tokens stored as SHA-256 hashes; tokens/passwords never logged (Logger redacts known keys).
* **Webhooks:** Meta verify-token challenge; HMAC signature verification with `hash_equals`; per-account URL token; dedupe via unique `(provider, event_key)`; ack fast (200) and process on the queue.
* **Authorization:** permission-based `Gate`, checked in middleware and again in services for sensitive ops; super admin is a platform flag, not a tenant role.
* **Audit:** login, logout, 2FA changes, role/user changes, account credential changes, tenant suspension, exports.

## 8. Phased roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Architecture (this file) | Done |
| 2 | Kernel, migrations, auth, 2FA, RBAC, tenancy, audit, super admin, layout | This delivery |
| 3 | Contacts, conversations, messages, assignment, notes, tags, dashboard | This delivery |
| 4 | Provider adapters, webhooks, queue worker, outbound & statuses, API v1 | This delivery |
| 5 | Templates, campaigns (consent, suppression, throttling, dry run), automation engine | Next |
| 6 | AI provider layer, knowledge base, deterministic chatbot flows, handoff | Planned |
| 7 | Outgoing webhooks, integrations framework, OpenAPI doc | Planned |
| 8 | Leads/pipelines/deals, reports, billing & usage quotas | Planned |
| 9 | Security hardening pass, more tests | Continuous |
| 10 | Deployment guide, backups, monitoring | Initial guide in this delivery |

See `docs/FEATURES.md` for the live implemented / partial / not-yet list.
