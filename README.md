# WhatsApp CRM

Multi-tenant WhatsApp CRM in plain PHP 8.3 — no framework, no Composer dependencies.
Shared team inbox, contacts, roles & permissions, the official Meta WhatsApp Cloud API
(plus an optional, clearly-flagged OpenWA gateway adapter), webhooks, a queue worker and a REST API.

**Status:** core CRM, team inbox, Meta template approval, campaigns, automations and "Continue with Facebook" are
implemented and tested. AI/chatbots, pipelines and billing are planned — see [`docs/FEATURES.md`](docs/FEATURES.md).

## Requirements

| | Version |
|---|---|
| PHP | 8.3+ with `pdo_mysql` (or `pdo_sqlite`), `curl`, `mbstring`, `openssl`, `fileinfo`; `sodium` recommended |
| Database | MySQL 5.7.8+ / MariaDB 10.3+ (MySQL 8 recommended) · SQLite 3.35+ (dev/tests) |
| Web server | Apache or LiteSpeed (shared hosting, `.htaccess` included) or Nginx + PHP-FPM; HTTPS |
| Background work | Shared hosting: one cron job (`bin/console cron`). VPS: systemd worker + cron |

## Install

Upload, open it in a browser, and the **built-in installer** handles the rest (requirements check → database →
accounts → done), then locks itself.

| Where | How |
|---|---|
| **Shared Linux hosting** (cPanel, Hostinger, DirectAdmin, Plesk) | Extract to your home folder, point a subdomain at `whatsapp-crm/public`, open it, add one cron job — **[docs/SHARED_HOSTING.md](docs/SHARED_HOSTING.md)** |
| VPS | Nginx/Apache + PHP-FPM, systemd worker — [docs/INSTALL.md](docs/INSTALL.md#b-on-a-server-vps-ubuntu--nginx-or-apache) |
| Your computer | `./start.sh` |
| Terminal only | `php bin/console setup` (instant, SQLite) or `php bin/console install` (asks questions) |

Run the tests: `php tests/run.php` (each test uses a fresh in-memory database).

## Layout

```
public/            web root (index.php + assets) — the only directory exposed to the web
src/Core           kernel: router, request/response, DB, migrations, sessions, crypto, queue, validator
src/Auth           login, TOTP 2FA, password policy, API tokens
src/Tenancy        tenant context + tenant-scoped repository base class
src/Rbac           permission catalogue, default roles, gate
src/Domain         contacts, conversations, inbox service
src/WhatsApp       provider interface, Meta Cloud + OpenWA adapters, webhook & send jobs
src/Http           controllers + middleware
views/             templates (all output escaped with e())
database/migrations, bin/console, tests/, deploy/, docs/
```

## Documentation

- [Installing on shared hosting](docs/SHARED_HOSTING.md) · [all install options](docs/INSTALL.md)
- [Architecture (Phase 1)](docs/ARCHITECTURE.md)
- [Deployment, backups, upgrades, troubleshooting](docs/DEPLOYMENT.md)
- [Connecting WhatsApp](docs/WHATSAPP_SETUP.md)
- [REST API](docs/API.md) · [OpenAPI spec](docs/openapi.yaml)
- [Security checklist](docs/SECURITY.md)
- [User & admin guide](docs/USER_GUIDE.md)
- [Feature status](docs/FEATURES.md)
