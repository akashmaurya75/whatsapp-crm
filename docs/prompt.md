# MASTER DEVELOPMENT PROMPT: COMPLETE WHATSAPP CRM & AUTOMATION PLATFORM IN PHP

## 1. Project Overview

Act as a senior software architect, PHP developer, UI/UX designer, database architect, cybersecurity engineer, and WhatsApp integration specialist.

Build a complete, production-ready, multi-user WhatsApp CRM and automation platform inspired by OpenWA, with functionality comparable to a WhatsApp messaging gateway, customer relationship management system, team inbox, marketing automation platform, and AI-powered chatbot.

The platform must be a working software application—not merely a dashboard template or static UI. It must include a functional frontend, backend, database, API integrations, automation engine, reporting system, security controls, and installation documentation.

**Project name:** WhatsApp CRM  
**Suggested stack:** PHP 8.3+, Laravel 12 or a mutually compatible stable Laravel release, MySQL 8+, Redis, HTML5, CSS3, JavaScript, Bootstrap 5 or Tailwind CSS, and REST APIs.

Use mutually compatible stable versions available at implementation time. Document exact versions and system requirements.

## 2. Architecture and Technology Requirements

Build a modular, scalable, maintainable application:

- Backend: PHP with Laravel.
- Database: MySQL.
- Frontend: Blade templates with a modern responsive component system.
- JavaScript: Vanilla JavaScript or Alpine.js; use Vue only where it provides a clear benefit.
- Background processing: Laravel queues with Redis or a compatible queue driver.
- Real-time updates: Laravel broadcasting with WebSockets or an equivalent solution.
- Authentication: Secure Laravel authentication with optional two-factor authentication.
- API: Versioned REST API with JSON responses.
- File storage: Local storage and optional S3-compatible object storage.
- Caching: Redis where available.
- Deployment: Linux VPS, Nginx or Apache, PHP-FPM, HTTPS, and Docker support.
- Testing: PHPUnit or Pest, with unit, feature, API, and integration tests.

Keep business logic in services, actions, jobs, and domain modules rather than placing everything in controllers.

Use database migrations, seeders, form requests, policies, middleware, queues, events, listeners, API resources, and dependency injection.

Do not hardcode credentials, API keys, tenant IDs, file paths, or business rules.

## 3. WhatsApp Integration Layer

Create a dedicated WhatsApp integration module with an adapter-based architecture.

Support:
1. Official Meta WhatsApp Business Platform Cloud API.
2. An optional, independently deployable OpenWA-compatible gateway adapter.
3. Future providers through the same adapter interface.

Requirements:
- Use the official Meta API for supported production business messaging.
- Keep unofficial gateway integrations optional and isolated.
- Never represent an unofficial WhatsApp Web session as an official Meta API connection.
- Display provider type and connection health for every account.
- Support provider-specific capabilities without pretending all providers expose identical functionality.

The adapter should provide methods for:
- Connecting and disconnecting accounts.
- Sending text messages.
- Sending images, documents, audio, and videos.
- Sending supported templates and interactive messages.
- Receiving inbound messages.
- Receiving message-status updates.
- Retrieving supported chat and contact information.
- Managing supported groups, participants, and invitation links.
- Handling supported reactions, replies, and message edits.
- Uploading and retrieving media.
- Registering and processing webhooks.
- Checking connection status.
- Handling rate limits and provider errors.
- Retrying eligible failed requests.
- Normalizing provider-specific events into a common internal event format.

Implement feature detection so unavailable capabilities are disabled or clearly marked.

Do not claim unsupported group, channel, message-editing, or other operations are available through the official Cloud API.

## 4. Multi-Tenant SaaS Architecture

Support multiple companies using the same application. Each tenant must have its own:
- Users, roles, and permissions.
- WhatsApp accounts.
- Contacts and leads.
- Conversations and messages.
- Teams and agents.
- Campaigns and automation rules.
- Chatbots and templates.
- Reports and API credentials.
- Integration settings.
- Billing and subscription records.

Enforce strict tenant isolation in application services, queries, policies, jobs, exports, and API endpoints. Every tenant-owned record must be scoped to the authenticated tenant. Prevent cross-tenant access even if record IDs or request parameters are manipulated.

Create a platform super-admin role to manage tenants, subscription plans, platform settings, and system health without unnecessary access to private customer conversations.

Provide tenant onboarding, activation/suspension controls, usage limits, and plan-based feature restrictions.

## 5. Authentication, Users, and Permissions

Implement:
- Secure registration and login.
- Email verification and password reset.
- Optional two-factor authentication.
- Session management and logout from all devices.
- Configurable password policies.
- Role-based and permission-based access control.
- Team and department management.
- Agent invitation and onboarding.
- User activation and suspension.
- Login history and audit trails for sensitive actions.

Default roles:
- Platform Super Admin.
- Company Owner.
- Company Admin.
- CRM Manager.
- Team Leader.
- Sales Agent.
- Customer Support Agent.
- Marketing Manager.
- Read-Only Analyst.

Allow administrators to create custom roles and assign granular permissions.

## 6. Main Dashboard

Build a modern dashboard with real database-driven metrics:
- Total contacts and new leads.
- Total, open, unassigned, and awaiting-response conversations.
- Messages sent and received.
- Campaign delivery and failure statistics.
- Agent response time and average resolution time.
- Conversion rate and lead pipeline value.
- Follow-ups due today.
- Automation execution statistics.
- AI chatbot performance.
- WhatsApp account connection status.
- API, storage, and AI usage.

Add date, team, WhatsApp account, and campaign filters. All figures must use actual database data and respect tenant permissions.

## 7. Unified Team Inbox

Build a professional WhatsApp-style shared inbox.

**Layout**
- Left: contacts, conversations, search, and filters.
- Center: conversation history and message composer.
- Right: contact profile, assigned agent, tags, notes, lead details, and activity history.

**Features**
- Real-time inbound and outbound messages.
- Send text and supported media.
- Reply to specific messages.
- Forward messages and add reactions where supported.
- Display timestamps and delivery statuses.
- Search conversations and messages.
- Pin, archive, mark read/unread, close, and reopen conversations.
- Assign conversations to agents and teams.
- Transfer conversations between agents.
- Add internal notes invisible to customers.
- Add/remove tags and set priorities.
- Create follow-up reminders.
- View complete customer interaction history.
- Deduplicate inbound webhook events.
- Show unread counts and typing indicators where supported.
- Keyboard shortcuts and pagination/virtual scrolling for long histories.

Use real providers. Never mark a message as successfully sent when the provider has not accepted it.

## 8. Contact and Lead Management

Contact fields:
- Name.
- Phone number in international E.164 format.
- Email and company.
- City and address.
- Source and lead status.
- Lead owner and assigned team.
- Tags and custom fields.
- Notes and last-contact date.
- Next follow-up date.
- Consent and communication preferences.
- Created date and last activity.

Features:
- Create, edit, merge, and archive contacts.
- Duplicate detection using normalized phone numbers and configurable identifiers.
- Bulk import from CSV and Excel, with preview and validation.
- Export authorized contact data.
- Bulk assignment and tagging.
- Custom fields and contact segmentation.
- Saved filters and activity timeline.
- Lead scoring using configurable rules.
- Import error reports and source attribution.
- Search by name, phone, company, and supported fields.

Respect opt-outs and suppression lists when importing or sending campaigns.

## 9. Sales Pipeline and Deal Management

Implement a visual Kanban sales pipeline:
- Multiple pipelines and custom stages.
- Drag-and-drop stage transitions.
- Deal title, value, associated contact, salesperson, expected close date, source, and probability.
- Notes, activities, and task reminders.
- Won/lost tracking and lost reasons.
- Stage conversion analytics and salesperson reports.

Allow automated actions when a deal enters or leaves a stage.

## 10. WhatsApp Campaign Manager

Build a campaign manager for authorized messaging.

Features:
- Create campaigns and choose an eligible WhatsApp account.
- Select an audience or saved segment and apply exclusion lists.
- Select approved templates where required.
- Personalize supported template variables.
- Preview messages using sample contacts.
- Upload and validate recipient lists.
- Schedule, pause, resume, and cancel campaigns.
- Configure sending rate limits.
- Process recipients through background queues.
- Track submitted, sent, delivered, read, and failed statuses when provided by the API.
- Record provider errors and retry eligible transient failures.
- Prevent duplicate sends.
- Export campaign reports and compare performance.
- Dry-run mode to preview the audience and payload without sending.

Implement consent verification, opt-out suppression, template compliance, configurable frequency limits, and auditable sending history. Never bypass provider limits or anti-spam controls or send unsolicited messages.

## 11. Bulk Messaging and Notification Engine

Support:
- Order confirmations.
- Appointment and payment reminders.
- Delivery notifications.
- Customer support updates.
- Lead follow-ups.
- Event reminders.
- Subscription notifications.
- Internal operational alerts.

Provide reusable templates, personalization variables, queue processing, scheduling, delivery callbacks, retry policies, failure logs, idempotency keys, per-account throttling, and audit trails.

Distinguish between free-form messages permitted within the applicable customer-service window and approved templates required outside it. Validate provider-specific requirements before dispatch.

## 12. Visual Automation Builder

Build a visual node-based workflow editor.

**Triggers**
- New inbound message, contact, or lead.
- Contact updated or lead stage changed.
- Incoming webhook.
- Conversation assigned or closed.
- Scheduled time or follow-up due.
- Message delivery failure.
- Payment/order event from an integrated system.

**Conditions**
- Message contains a keyword.
- Contact matches a saved segment.
- Tag exists or lead status matches.
- Business-hours or assigned-team condition.
- Custom field comparison.
- Previous interaction condition.
- Consent and suppression checks.

**Actions**
- Send an approved template or permitted message.
- Assign a conversation or team.
- Add/remove a tag.
- Update contact fields.
- Create a lead, deal, or follow-up task.
- Notify an agent.
- Call a configured webhook.
- Wait, branch, escalate to a human, invoke an AI assistant, or stop/handoff a workflow.

Implement draft/publish states, version history, execution logs, retries, loop prevention, execution time limits, queue management, idempotency, workflow simulation, and sample-event testing.

Never execute arbitrary PHP supplied by a tenant through workflow nodes.

## 13. AI Chatbot and AI Agent Module

Create an AI integration layer supporting configurable providers such as OpenAI and other compatible APIs.

Features:
- AI-generated replies to incoming messages.
- Configurable system instructions and company-specific knowledge bases.
- FAQ management and document ingestion where supported.
- Website content ingestion through an explicitly configured crawler.
- Semantic search and retrieval-augmented generation.
- Configurable model selection, response length, and token limits.
- Per-tenant usage tracking, response logs, and estimated cost.
- Human handoff, confidence thresholds, and fallback responses.
- Conversation context management and language detection.
- Multilingual responses.
- Agent takeover and AI pause.
- Conversation summaries and suggested replies.

Optional tools:
- Speech-to-text for supported audio formats.
- Image understanding.
- Product catalog search.
- Order lookup.
- Appointment availability lookup.
- CRM lead updates through permission-checked internal tools.

Do not allow AI to execute unrestricted SQL, access other tenants, expose credentials, or invoke arbitrary internal functions. Validate all tool calls server-side. Require confirmation or human approval for sensitive actions such as refunds or important business-record changes.

## 14. Chatbot Flow Builder

Create a no-code deterministic chatbot builder with:
- Welcome message.
- Text and media response.
- Interactive list/button messages where supported.
- Question and answer.
- Collect name, phone, email, or custom lead information.
- Multiple-choice question.
- Conditional branching and keyword matching.
- Business-hours check and delay.
- API request.
- CRM contact creation.
- Agent handoff and end conversation.

Provide visual preview, validation, versioning, and publishing. Disable unsupported message types for providers that cannot send them.

## 15. Webhooks and Public API

Build a versioned REST API starting at `/api/v1`.

Include endpoints for:
- Authentication and API token management.
- WhatsApp account management.
- Contact CRUD.
- Lead and deal management.
- Conversation retrieval and message sending.
- Media upload.
- Campaign creation and reporting.
- Automation and chatbot management.
- Template management.
- Team and user management.
- Webhook subscription management.
- Reports and usage metrics.

Requirements:
- JSON request/response formats.
- Request validation and tenant-scoped authorization.
- Rate limiting, pagination, filtering, and sorting.
- Consistent errors, API versioning, and request IDs.
- Idempotency for relevant write operations.
- OpenAPI/Swagger documentation and examples.

For webhooks:
- Verify Meta subscription challenges.
- Validate provider signatures and timestamps where supported.
- Apply replay protection where supported.
- Deduplicate events and store only necessary event data.
- Acknowledge promptly and process asynchronously.
- Retry failed deliveries with bounded exponential backoff.
- Sign outgoing webhook payloads and allow secret rotation.

## 16. WhatsApp Account and Session Management

For every account, display:
- Account name and provider type.
- Phone number and business identifiers where applicable.
- Connection status and last successful synchronization.
- Webhook and API health.
- Daily/monthly usage and error history.

For supported OpenWA-compatible gateways:
- Connect using supported QR/pairing-code mechanisms.
- Display session status.
- Request reconnection or disconnect.
- View supported session events.
- Show capability differences by gateway/version.

Never store session credentials in plain text or expose QR/session tokens to unauthorized users. Do not present unofficial WhatsApp Web sessions as a substitute for Meta Cloud API without clearly identifying provider risks and limitations.

## 17. Message Templates

Implement:
- Internal template creation and editing.
- Variables and categories.
- Personalized previews.
- Template versioning.
- Approval status where available.
- Synchronization of supported Meta-approved templates.
- Provider rejection reasons.
- Validation that required approvals exist before sending.

Support utility, authentication, and marketing categories where the connected provider supports them.

## 18. Analytics and Reports

Create reports for:
- Message volume, delivery, read, and failure rates.
- Campaign performance.
- Agent productivity and first-response/resolution time.
- Conversation volume over time.
- Lead source and sales pipeline conversion.
- Revenue attribution when reliable data is available.
- Chatbot containment and human handoff.
- Automation success/failure.
- WhatsApp account usage.
- AI token usage and estimated cost.
- Subscription usage and API consumption.

Add date filters, CSV exports, permission checks, and tenant-specific reporting. Never invent unavailable delivery, read, conversion, or revenue data.

## 19. Integrations

Create a reusable integration framework for:
- Meta WhatsApp Business Platform.
- OpenWA-compatible gateways.
- OpenAI or other configured AI providers.
- SMTP email.
- n8n.
- Webhooks.
- WordPress and WooCommerce.
- Shopify.
- Payment gateways.
- Calendar services.
- Google Sheets through an authorized API.
- External CRMs and analytics platforms.

Each integration needs a configuration screen, secure credential storage, connection testing, enable/disable controls, health status, error logs, credential rotation where applicable, and documentation.

Implement only integrations with available credentials and documented APIs. Mock adapters may be used in tests but must never be reported as production success.

## 20. Tasks, Follow-Ups, and Notifications

Implement:
- Tasks associated with contacts, leads, and deals.
- Task assignment, due dates, and priorities.
- Recurring reminders where needed.
- Today's and overdue task views.
- Notifications for assignments and overdue conversations.
- Internal, email, and in-app notifications.
- Notification history, read status, and user preferences.

## 21. Search and Filtering

Implement global search across authorized contacts, leads, deals, conversations, messages, campaigns, tasks, and templates.

Provide saved filters, advanced search, pagination, and performance optimization. Never expose records belonging to other tenants or unauthorized teams.

## 22. Subscription and Billing Module

For a SaaS deployment, include:
- Subscription plans and trials.
- Monthly and annual billing.
- Feature restrictions by plan.
- Tenant usage quotas.
- Agent, WhatsApp account, AI usage, and storage limits.
- Payment gateway integration through documented APIs.
- Invoice generation and payment history.
- Renewal reminders and grace periods.
- Suspension and reactivation.
- Super-admin subscription management.

Do not hardcode prices or assume WhatsApp messaging fees are included in the software subscription. Configure provider- and country-specific messaging charges separately.

## 23. Super Admin Panel

Build a separate platform administration panel with:
- Tenant and user management.
- Subscription plans and billing records.
- Feature flags and system configuration.
- Queue, scheduler, webhook, and provider health.
- Error and audit logs.
- Storage and database health.
- API and AI usage.
- Rate-limit configuration.
- Maintenance mode.
- Tenant suspension/reactivation.

Sensitive actions require appropriate authorization and must be audited.

## 24. UI/UX Requirements

Create a professional SaaS dashboard inspired by modern CRM products:
- Clean, collapsible sidebar.
- Top navigation, search, breadcrumbs, profile menu, and notification center.
- Responsive desktop, tablet, and mobile layouts.
- Mobile-friendly inbox.
- Light and dark themes.
- Tables with sorting, filtering, and pagination.
- Kanban boards, modal forms, confirmation dialogs, and toast notifications.
- Loading, empty, and error states.
- Form validation and accessible keyboard navigation.
- Consistent spacing, typography, and reusable components.

Avoid excessive gradients, unnecessary animations, and overcrowded dashboards. Every visible control must perform a meaningful action.

## 25. Database Design

Create normalized migrations and relationships for, at minimum:
- `tenants`
- `subscription_plans`
- `subscriptions`
- `users`
- `roles`
- `permissions`
- `teams`
- `team_members`
- `whatsapp_accounts`
- `whatsapp_provider_configs`
- `contacts`
- `contact_custom_fields`
- `contact_field_values`
- `contact_tags`
- `conversations`
- `conversation_assignments`
- `messages`
- `message_media`
- `message_status_events`
- `message_templates`
- `campaigns`
- `campaign_recipients`
- `campaign_events`
- `leads`
- `pipelines`
- `pipeline_stages`
- `deals`
- `tasks`
- `notes`
- `automation_workflows`
- `workflow_versions`
- `workflow_nodes`
- `workflow_executions`
- `chatbot_flows`
- `chatbot_versions`
- `chatbot_sessions`
- `ai_knowledge_sources`
- `ai_documents`
- `ai_usage_logs`
- `webhook_endpoints`
- `webhook_deliveries`
- `api_tokens`
- `audit_logs`
- `notifications`
- `integration_configs`
- `usage_records`
- `invoices`
- `payment_transactions`

Add appropriate foreign keys, indexes, unique constraints, tenant identifiers, timestamps, soft deletes where appropriate, and retention policies.

Use JSON columns only when flexible structured data is justified. Store large media files in object storage rather than database blobs. Normalize phone numbers and enforce idempotency on external message and webhook identifiers where appropriate.

Document the purpose and relationships of every major table.

## 26. Security and Privacy

Follow secure coding practices and applicable privacy laws.

Implement:
- CSRF protection.
- SQL injection protection.
- Output escaping against XSS.
- Secure password hashing.
- Authorization policies and tenant isolation.
- API rate limits.
- Webhook signature verification.
- Encryption of sensitive integration credentials.
- HTTPS enforcement and secure session cookies.
- Secret management via environment variables or a secret manager.
- Upload validation and file-size restrictions.
- Protection against path traversal and malicious uploads.
- Audit logging.
- Data export controls.
- Configurable data retention.
- Account and data deletion workflows.
- Consent and opt-out management.
- Backup and recovery documentation.

Do not log access tokens, passwords, full payment credentials, or unnecessary sensitive message contents. Provide workflows for applicable access, correction, and deletion requests.

## 27. Reliability and Performance

Implement:
- Queue-based messaging.
- Database transactions for critical changes.
- Idempotent webhook processing.
- Retry handling for transient failures.
- Dead-letter or failed-job management.
- Connection health checks.
- Structured logging and error-monitoring hooks.
- Pagination and database indexes.
- Suitable caching.
- Queue and worker monitoring.
- Configurable rate limits.
- Backup and restore procedures.
- Graceful handling of provider downtime.
- Clear operator alerts.

Avoid synchronous campaign dispatch or long-running AI requests in ordinary web requests.

## 28. Testing and Acceptance Criteria

Write automated tests for:
- Authentication and authorization.
- Tenant isolation.
- Contact CRUD and duplicate prevention.
- Conversation assignment.
- Message dispatch through a mocked provider.
- Webhook signature verification and deduplication.
- Message status updates.
- Campaign scheduling.
- Consent and opt-out enforcement.
- Automation triggers and retries.
- AI tool authorization.
- Subscription limits.
- API rate limits.
- Data export permissions.

The application is not complete until:
1. A fresh installation succeeds using documented steps.
2. Database migrations run successfully.
3. An administrator can create and manage a tenant.
4. Users can sign in with appropriate permissions.
5. A real WhatsApp provider can be configured with valid credentials.
6. Inbound messages are received and stored correctly.
7. Outbound messages are dispatched and actual statuses tracked.
8. Contacts and leads can be managed.
9. Conversations can be assigned to agents.
10. Campaigns can be tested and executed with safeguards.
11. Automation rules execute from real events.
12. Reports use actual stored data.
13. Tenant boundaries are verified with automated tests.
14. Errors are logged without exposing secrets.
15. The application works on desktop and mobile.
16. Installation, backup, upgrade, and troubleshooting documentation is complete.

## 29. Deliverables

Provide:
1. Complete source code.
2. Composer dependencies and lock file.
3. Frontend assets and build configuration.
4. Database migrations.
5. Seeders and demo data.
6. `.env.example` with placeholders only.
7. Provider integration adapters.
8. API routes and OpenAPI documentation.
9. Queue jobs and scheduled tasks.
10. Automated tests.
11. Installation and production deployment guides.
12. Database backup and restore guide.
13. WhatsApp provider configuration guide.
14. AI integration guide.
15. Super-admin and tenant-admin documentation.
16. User manual.
17. Security checklist.
18. Troubleshooting guide.
19. A list of implemented, partially implemented, and unsupported features.

## 30. Mandatory Development Workflow

Do not generate an enormous application as one unstructured code dump. Work in phases.

### Phase 1 — Architecture
- Confirm framework/version compatibility.
- Define modules, boundaries, and API contracts.
- Design database relationships and tenant isolation.
- Produce an implementation roadmap.

### Phase 2 — Foundation
- Create the Laravel project.
- Configure authentication, database, roles, permissions, and tenant isolation.
- Build shared UI layouts and administration panels.

### Phase 3 — CRM Core
- Implement contacts, leads, deals, tasks, teams, and inbox data models.

### Phase 4 — WhatsApp Integration
- Implement provider adapters, webhook ingestion, outbound messaging, status handling, and media.

### Phase 5 — Campaigns and Automation
- Implement queues, campaigns, workflow execution, and audit logs.

### Phase 6 — AI and Chatbots
- Implement AI adapters, knowledge retrieval, deterministic chatbot flows, and human handoff.

### Phase 7 — APIs and Integrations
- Implement external APIs, webhook subscriptions, integration settings, and documentation.

### Phase 8 — Analytics and Billing
- Implement reporting, usage controls, subscriptions, and platform administration.

### Phase 9 — Security and Testing
- Run automated tests, security checks, and tenant-isolation tests. Fix discovered defects before production.

### Phase 10 — Deployment
- Provide production configuration, migrations, workers, scheduler setup, backups, monitoring, and operational documentation.

At every phase:
- Produce actual runnable code.
- State exact file paths for created or modified files.
- Provide complete file contents when working in a text-only coding environment.
- Avoid pseudocode for required functionality.
- Never claim a feature is complete if it is only a placeholder.
- Run relevant tests and report actual results.
- Preserve working functionality when adding modules.
- Document external credentials and paid services required.
- Maintain an updated feature checklist.
- Ask questions only when a missing decision materially affects architecture; otherwise use documented defaults.

## 31. Final Instruction

Begin with Phase 1.

First produce the complete system architecture, module breakdown, database relationship design, API structure, provider compatibility matrix, security model, and phased implementation plan.

Then proceed to implementation in small, testable increments.

The final result must be a secure, maintainable, extensible WhatsApp CRM built with PHP that can be deployed on a VPS and expanded into a commercial multi-tenant SaaS product.

Prioritize real functionality, maintainability, provider compatibility, security, tenant isolation, and reliable messaging over superficial visual appearance.
