# REST API v1

Base URL: `https://<your-domain>/api/v1` · JSON in, JSON out · machine-readable spec: [`openapi.yaml`](openapi.yaml)

## Authentication

Create a token under **API tokens** (needs `api_tokens.manage`). Send it on every request:

```
Authorization: Bearer wcrm_xxxxxxxxxxxxxxxx
```

A token acts as the user who created it — same company, same permissions. Suspending the user or the company,
revoking the token, or reaching its expiry stops it immediately.

## Conventions

| | |
|---|---|
| Pagination | `?page=1&per_page=25` (max 100) → `{"data":[…],"meta":{"page":1,"per_page":25,"total":42}}` |
| Errors | `{"error":{"code":"validation_failed","message":"…","details":{"phone":"…"}}}` with HTTP 4xx/5xx |
| Request id | Every response has `X-Request-Id`; include it when reporting problems |
| Rate limit | `API_RATE_LIMIT_PER_MINUTE` per token (default 120). Exceeding returns `429` with `Retry-After` |
| Idempotency | Send `Idempotency-Key: <unique>` on POST. Same key + same body replays the first response (`Idempotent-Replayed: true`); same key + different body → `409` |
| Times | UTC, `YYYY-MM-DD HH:MM:SS` |

Error codes: `unauthenticated` (401), `forbidden` (403), `not_found` (404 — also returned for other companies' records),
`validation_failed` (422), `idempotency_conflict` (409), `rate_limited` (429), `server_error` (500).

## Endpoints

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/me` | — | Token owner and company |
| GET | `/contacts` | contacts.view | Filters: `q`, `lead_status`, `tag`, `archived=true`, `sort` (name, created_at, last_contacted_at, lead_status), `dir` |
| POST | `/contacts` | contacts.create | Body: `phone` (required, any format → E.164), `name`, `email`, `company`, `city`, `address`, `source`, `lead_status`, `consent_status`, `owner_user_id`, `next_follow_up_at`, `tags[]` |
| GET | `/contacts/{id}` | contacts.view | Includes `tags` |
| PATCH | `/contacts/{id}` | contacts.update | Any POST field; `tags` replaces the tag set |
| DELETE | `/contacts/{id}` | contacts.delete | Archives (soft delete) → 204 |
| GET | `/conversations` | inbox.view | Filters: `status` (open, closed, archived), `assigned` (me, unassigned, awaiting), `q`, `whatsapp_account_id` |
| GET | `/conversations/{id}/messages` | inbox.view | Newest `per_page` messages, oldest first; `before_id` for older pages |
| POST | `/conversations/{id}/messages` | inbox.reply | `{"body":"text"}` → `202` with `status: "queued"`; delivery status arrives asynchronously |
| GET | `/whatsapp-accounts` | whatsapp.view | Provider, `official` flag, status, capabilities |
| GET | `/templates` | templates.view | `?status=approved`. Each item lists its `variables` (e.g. `body_1`, `body_2`, `button_0`) and whether it is `sendable` |
| POST | `/messages/template` | inbox.reply | Send an approved template to a phone number (creates the contact if new). See below |
| GET | `/campaigns` | campaigns.view | Latest 100 campaigns with live stats |
| GET | `/campaigns/{id}` | campaigns.view | One campaign: recipients, sent, delivered, read, replied, failed, skipped |

## Example

```bash
curl -s https://crm.example.com/api/v1/contacts \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-1042-contact" \
  -d '{"phone":"+91 98765 43210","name":"Meera","source":"shopify","consent_status":"granted","tags":["customer"]}'
```

```json
{"data":{"id":12,"name":"Meera","phone":"+919876543210","lead_status":"new","consent_status":"granted", "...": "..."},"request_id":"5f2c…"}
```

### Sending notifications (order, payment, delivery updates)

```bash
curl -s https://crm.example.com/api/v1/messages/template \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-1042-shipped" \
  -d '{"phone":"+919876543210","name":"Meera","template":"order_shipped","language":"en",
       "variables":{"body_1":"Meera","body_2":"#1042","body_3":"Friday","button_0":"1042"}}'
```

- `template` is the template name; `language` is optional (first approved match is used).
- `variables` uses the keys returned by `GET /templates`. All are required; WhatsApp rejects empty values.
- `media_url` (https) is required when the template has an image, video or document header; `filename` is optional.
- Opted-out numbers are refused with `422`. Template messages are allowed outside the 24-hour window.
- Returns `202` with the queued message; delivery status follows asynchronously.

Message statuses: `queued` (stored, waiting for worker) → `submitted` (provider accepted) → `sent` → `delivered` → `read`, or `failed`
with `error_code`/`error_message` from the provider. A status is never reported unless the provider reported it.
