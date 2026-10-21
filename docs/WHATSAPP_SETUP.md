# Connecting WhatsApp

## Easiest: "Continue with Facebook" (one click per company)

Once the platform admin has set up the Meta app (below), anyone with *Connect and configure WhatsApp accounts*
permission opens **WhatsApp accounts → Continue with Facebook**:

1. Sign in with the Facebook account that manages the business.
2. Choose or create the business portfolio and WhatsApp Business account, then add and verify the number. The number must
   not be active in the WhatsApp app; delete that account first, or use a new number.
3. The app then does everything automatically:
   - exchanges the 30-second code for a business token (server-side, stored encrypted);
   - checks that the number belongs to the shared account;
   - subscribes to its webhooks;
   - registers the number (with a two-step PIN it creates, or yours under *Advanced*);
   - creates the account and imports its approved templates.
4. If the business account has several numbers, you pick one.
5. Reconnecting the same number updates it. A number already connected by another company is refused.
6. Add a payment method in WhatsApp Manager so template messages can be sent (Meta bills WhatsApp conversations directly).

### One-time platform setup (super admin → **Meta app**)

1. In **developers.facebook.com**, create a *Business* app linked to your verified business portfolio, and add the **WhatsApp** product.
2. **App settings → Basic:** copy the **App ID** and **App Secret** into Admin → Meta app. Add your domain under *App domains* and set a privacy policy URL.
3. **Facebook Login for Business → Settings:** enable *Login with the JavaScript SDK* and add `https://your-domain` to *Allowed domains*.
4. **Facebook Login for Business → Configurations → Create:**
   - choose *WhatsApp Embedded Signup* and select the WhatsApp product (this gives Embedded Signup **v4**);
   - grant the permissions `whatsapp_business_management` and `whatsapp_business_messaging`;
   - set *Access token* to **System-user access token** with token expiration **Never** (with *60 days*, every company
     must reconnect every two months);
   - copy the **configuration ID** into Admin → Meta app.
5. **WhatsApp → Configuration → Webhook:**
   - paste the **Callback URL** (`https://your-domain/webhooks/meta`) and the **Verify token** shown on the Admin page;
   - subscribe to `messages`, `message_template_status_update` and `account_update`.
6. Press **Test App ID & Secret with Meta**.
7. To onboard **other** businesses, complete business verification and app review for the two permissions (become a Meta
   *Tech Provider*). Until then only people with a role on your Meta app can use the button.

Requirements:
- The site must be served over **https** (for local testing use a tunnel such as `cloudflared`).
- The app uses Graph API **v25.0** (`META_GRAPH_VERSION`). Embedded Signup v2 is retired by Meta on 15 Oct 2026, and this app uses v4.

Staying connected:
- Each number's token is checked with Meta (`debug_token`) when it is connected and once a day (`schedule:run`);
  Admin → Meta app shows how many tokens never expire, expire, or need reconnecting, and can check them all on demand.
- A week before a token expires, the company's owners and admins get an email and an in-app banner.
- If Meta rejects a token (error 190: expired, revoked, or the user removed the app), the number is marked
  **needs reconnect**: sends stop, its running and scheduled campaigns are paused, and owners and admins are emailed once.
- **Reconnect with Facebook** (banner, account page or email link) runs the same sign-in and updates the number in place.
  Conversations, templates and settings are kept; resume paused campaigns afterwards.

Security:
- The App Secret and every business token are encrypted at rest and never sent to the browser.
- Platform webhooks are accepted only with a valid `X-Hub-Signature-256` from your app secret, then routed to the right
  company by business-account ID or phone-number ID.
- The Facebook SDK may load only on the connect page (a per-page content security policy).

## Manual setup (your own token)

### Meta WhatsApp Cloud API with your own system-user token

You need a Meta Business account, a Meta developer app with the **WhatsApp** product, and a phone number
registered in WhatsApp Manager. Meta charges per conversation/message category separately from this
software; check Meta's current pricing for your countries.

1. **Meta developer app** → WhatsApp → API Setup: note the **Phone number ID** and **WhatsApp Business Account ID**.
2. **Permanent token:** Business Settings → System users → add a system user (Admin) → assign the app and the
   WhatsApp account → *Generate token* with `whatsapp_business_messaging` and `whatsapp_business_management`.
   (The 24-hour temporary token on the API Setup page is for testing only.)
3. **App secret:** App settings → Basic → App secret. Used to verify `X-Hub-Signature-256` on every webhook.
4. In the CRM: **WhatsApp accounts → Connect Meta Cloud API**. Enter the IDs, token, app secret and a
   verify token you invent (any long random string). Save.
5. Copy the **webhook URL** shown on the account page. In the Meta app → WhatsApp → Configuration:
   Callback URL = that URL, Verify token = the same string → *Verify and save*. Subscribe to the **messages** and
   **message_template_status_update** fields (the second one makes template approvals/rejections appear automatically).
6. Optional, for image/video/document template headers: enter your **Meta App ID** (App settings → Basic) on the account.
   Meta needs it to receive the sample file you upload when creating such a template.
7. Click **Test connection** in the CRM. Status becomes *connected* when Meta returns the number's details.
8. Send a WhatsApp message to your business number; it appears in the Inbox within seconds (worker must be running).

**Rules the app enforces for Cloud API accounts**
- Free-form replies only within 24 hours of the customer's last message. Outside it, send an approved template
  (Inbox → *Send a template*).
- Contacts who opted out (STOP keywords or manual) cannot be messaged.
- Group management, message editing and typing indicators are not offered by the Cloud API and are disabled.

## Option B — OpenWA-compatible gateway (unofficial, optional)

> ⚠️ This automates a WhatsApp Web session. It is **not** an official Meta product, may break without notice,
> and may violate WhatsApp's Terms of Service — the number can be banned. Hide it entirely with `OPENWA_ENABLED=false`.

1. Run an [open-wa](https://github.com/open-wa/wa-automate-nodejs) EASY API instance on a private network, with an API key:
   `npx @open-wa/wa-automate --api-key <KEY> --port 8002`
2. Scan the QR code in the gateway's own console/UI to link the phone.
3. In the CRM: **Add OpenWA gateway** → base URL (e.g. `http://10.0.0.5:8002`) and the API key. Optionally set a
   signing secret if a proxy in front of the gateway adds an `X-Signature-256: sha256=<HMAC of body>` header.
4. Start the gateway with the account's webhook URL: `--webhook <URL from the account page>`.
5. **Test connection** calls `getConnectionState` and reports `CONNECTED` or the session state.

The webhook URL contains a 256-bit secret token that identifies the account; rotate it from the account page if leaked.

## Incoming messages don't appear in the Inbox

Open **WhatsApp accounts → your number → Check and fix**. It tests every link in the chain and fixes the most common
problem by itself:

| Check | What it means |
|---|---|
| Webhook address uses https | Meta only delivers to https. Set `APP_URL=https://…` in `.env` |
| Phone number ID and access token work | Wrong ID (the WABA ID pasted as phone number ID is common) or an expired token |
| Phone number belongs to the WhatsApp Business Account | The two IDs don't match |
| **Your Meta app is subscribed to the WhatsApp Business Account** | **Most common cause.** Without it Meta never forwards messages. "Check and fix" subscribes it automatically (the CRM also does this when you save the account or test the connection) |
| App ID and App Secret match | A wrong App Secret makes every webhook fail the signature check |
| Callback URL / "messages" field | The Meta app must send to this account's webhook URL and have **messages** subscribed |
| App mode | In Development mode, Meta only delivers messages from numbers listed under API Setup → "To". Switch the app to **Live** |
| Last delivery from Meta | When Meta last reached the server, and whether it was accepted or rejected (and why) |
| Background processing | The cron job (shared hosting) or worker must run, or received messages wait in the queue |

The Inbox refreshes by itself every 5 seconds: new chats appear at the top, with unread counts in the list and in the browser tab title.
