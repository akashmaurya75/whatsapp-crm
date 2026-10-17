# Feature status

Legend: ✅ implemented & tested · 🟡 partial · ⬜ not yet built. Section numbers refer to `prompt.md`.

## ✅ Implemented (Phases 1–4)

| § | Feature | Notes |
|---|---|---|
| 1–2 | Modular PHP 8.3 app, migrations, services, middleware, queue, tests | Framework-free (Packagist unavailable at build time) |
| 3 | Provider adapter interface, capability detection | `Provider`, `Capability`; UI disables unsupported features |
| 3, 16 | "Continue with Facebook" (Embedded Signup v4) | Super-admin Meta app settings (encrypted) with setup guide and credential test; one-click connect; code exchange, WABA/number verification, webhook subscription, number registration with PIN, account creation, template import; number picker; reconnect; cross-company protection; plan limit; platform webhook endpoint with signature check and routing; per-page CSP/COOP |
| 3, 16 | Meta Cloud API adapter | Send text/media, reply context, health check, webhook verify + HMAC signature, inbound & status parsing |
| 3, 16 | OpenWA gateway adapter (optional, labelled unofficial) | sendText/reply/sendImage/sendFile, connection state, onMessage/onAck, optional HMAC |
| 4 | Multi-tenancy | Tenant-scoped repositories, 404 on cross-tenant ids, tests; tenant suspension |
| 5 | Auth | Login, throttling, TOTP 2FA + recovery codes, password reset, invites, password policy, session timeouts, sign out everywhere, login history |
| 5 | RBAC | 19 permissions, 8 default roles per company, custom roles, owner protection |
| 6 | Dashboard | Real counts: conversations (open/unassigned/awaiting), messages in/out, delivery statuses, avg reply time, contacts, follow-ups, lead status, account health; date & account filters |
| 7 | Team inbox | 3-pane, real-time polling, reply-to, statuses, search, pin/archive/read/unread/close/reopen, assign/transfer to agent/team with history, internal notes, priority, keyboard shortcuts, pagination, mobile layout |
| 8 | Contacts | CRUD, E.164 normalisation, duplicate prevention, tags, notes, consent & opt-out (incl. STOP keyword), owner, follow-up date, archive/restore, search/filter/sort, CSV export (formula-safe) |
| 11 | Outbound engine (single messages) | Queue, idempotency keys, per-account throttle, bounded retries with backoff, permanent vs transient errors, 24-h window check, opt-out check at send time |
| 15 | REST API v1 | Tokens (hashed, expiry, revoke), contacts CRUD, conversations, messages, accounts; rate limit, idempotency, request ids, OpenAPI |
| 15 | Inbound webhooks | Verify challenge, signature, dedupe, async processing, replay-safe status ordering |
| 20 | Teams | Create/delete, membership, team-based visibility |
| 22–23 | Super admin | Companies (create/suspend/plan), plans (configurable prices/limits), health, failed-job retry, platform audit |
| 24 | UI | Responsive, light/dark, accessible labels/focus, empty/error states, confirmations, toasts |
| 26 | Security | See `SECURITY.md` |
| 27 | Reliability | DB transactions, idempotent jobs, failed-job store, worker heartbeat, hourly health checks, retention cleanup |
| — | Installer | Web wizard (requirements, MySQL/SQLite test + auto-create, accounts), CLI `install`/`setup`, self-locking, remote install key, start scripts, Apache/LiteSpeed .htaccess |
| — | Shared Linux hosting | Hosting-type choice; sub-folder and file-exposure self-checks; panel-specific help (cPanel, Hostinger, DirectAdmin, Plesk); app folder outside `public_html` found automatically; one `cron` command (locked, time-boxed) or secret web-cron URL; inline processing after responses (LiteSpeed/FPM); OpenSSL AES-GCM when `sodium` is missing; PHP 8.3 CLI path detection for cron; auto-migrate after upgrades; cron status on System health |
| 10 | Campaign manager | Audience by tags/exclusions/lead status/recent activity, variable mapping with fallbacks, media headers (upload or link), consent requirement or audited attestation, frequency cap, opt-out exclusion (twice: at start and at send), per-minute throttling, send now / schedule, pause/resume/cancel, dry-run preview with exclusion reasons, test send, live report (sent/delivered/read/replied/failed), funnel, recipient list, CSV export, duplicate |
| 12 | Automations (rule-based) | Welcome (once per contact), out-of-office with weekly business hours and time zone, keyword replies (exact/contains/starts) with actions (tag, assign team/agent, lead status), text or template replies, cooldowns, per-message and hourly caps, activity log |
| 17 | Message templates | Builder with live WhatsApp preview, Meta validation rules, text/image/video/document headers, body variables with samples, footer, quick-reply/URL (incl. dynamic suffix)/call buttons, submit for approval, approval/rejection via webhook, sync from WhatsApp Manager, quality score, duplicate, delete (also in Meta) |
| 8 | Contact import | CSV with header auto-mapping, tags, consent column or audited bulk consent, update/skip existing, opt-outs preserved, row-level error report |
| 11 | Template notifications API | `POST /api/v1/messages/template` by phone number, idempotent, creates contacts, respects opt-outs |
| 28 | Tests | 119 tests (incl. templates, campaigns, automations, import, installer, shared hosting): auth, 2FA, CSRF, XSS escaping, tenant isolation, permissions, webhooks (signature, dedupe, ordering, opt-out), dispatch (success/retry/permanent/idempotent), API (auth, idempotency, rate limit), crypto, TOTP vectors |

## 🟡 Partial

| § | Feature | What's missing |
|---|---|---|
| 5 | Email verification | Invited users verify by accepting; self-registered users are not yet asked to verify |
| 7 | Media in inbox | Inbound media is recorded (type, mime, Meta media id); download/preview and sending attachments from the UI are not built (adapter `sendMedia` exists) |
| 7 | Real time | 5-second polling, not WebSockets; typing indicators not shown |
| 8 | Import | CSV only (save Excel files as CSV); no merge; no custom fields or lead scoring |
| 17 | Templates | Authentication (OTP) templates sync but can't be created or sent from the UI; Flow/catalog buttons not supported |
| 18 | Reports | Dashboard only; no CSV report exports |
| 22 | Plan limits | Agent and WhatsApp-number limits enforced; contact limit stored but not enforced |
| 26 | Privacy workflows | Retention cleanup exists; self-service data export/deletion not built |

## ⬜ Not yet built (next phases)

- §9 Pipelines, deals, Kanban (Phase 8)
- §12 Visual drag-and-drop workflow builder (rule-based automations exist), delayed follow-ups, recurring campaigns
- §13 AI replies / knowledge base / RAG (Phase 6)
- §14 Chatbot flow builder (Phase 6)
- §15 Outgoing webhook subscriptions (Phase 7)
- §19 Integrations framework: SMTP client, n8n, WooCommerce, Shopify, Google Sheets, payments (Phase 7)
- §20 Tasks & in-app notifications
- §21 Global search across all entities
- §22 Invoices and payment-gateway billing
- Redis drivers for queue/rate limiting

## Unsupported by design

- Group management, message edit and typing indicators on the Meta Cloud API (the API doesn't offer them).
- Any feature that bypasses provider limits, opt-outs or anti-spam rules.
