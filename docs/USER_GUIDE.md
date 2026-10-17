# User & admin guide

## Platform super admin (`/admin`)

- **Companies:** create a company with its owner, change its plan, suspend (signs out all its users, stops API tokens and sending) or reactivate.
- **Plans:** create/update plans by code with price, currency, interval and limits (agents, WhatsApp accounts, contacts). The agent limit is enforced when inviting users. Prices are configuration — WhatsApp fees billed by Meta are separate.
- **System health:** database, worker heartbeat, storage, queue depth, provider account status, failed jobs (retry).
- **Meta app:** set up once to enable "Continue with Facebook" for every company. The page lists the exact steps and the webhook URL and token to paste into Meta.
- **Audit log:** platform-wide security events.
- The admin area never shows customer message contents.

## Company owner / admin

1. **Connect WhatsApp:** WhatsApp accounts → **Continue with Facebook** (one click), or connect manually with a token. See `WHATSAPP_SETUP.md`.
2. **Invite users** (Users) and pick a role. Invitees get an email link valid for 7 days.
3. **Teams:** group agents; conversations assigned to a team are visible to its members.
4. **Roles & permissions:** adjust default roles or create custom ones. Owner always has every permission and can only be granted by an owner.
5. **API tokens:** for integrations; tokens are shown once.
6. **Audit log:** who did what in your company.

### Default roles

| Role | Can |
|---|---|
| Company Owner / Admin | Everything |
| CRM Manager | Contacts incl. export/archive, see all conversations, assign, manage teams |
| Team Leader | Agent rights + assign/transfer |
| Sales / Support Agent | Dashboard, contacts (create/edit), conversations assigned to them, their teams or unassigned; reply; send templates |
| Marketing Manager | Contacts incl. import/export, templates, campaigns, automations |
| Read-Only Analyst | View dashboard, contacts and all conversations |

## Agents — the Inbox

- Left: conversations. Filter Open / Closed / Archived and All / Mine / Unassigned / Awaiting (customer wrote last). Search covers names, numbers and message text.
- Middle: the chat. **Enter** sends, **Shift+Enter** adds a line, **Reply** quotes a message, **r** focuses the composer, **/** focuses search. New messages and delivery ticks refresh every few seconds.
- Status ticks: ◷ queued (waiting to send) · ✓ submitted/sent · ✓✓ delivered/read · ! failed (hover for the reason).
- Right: contact, assignment (agents can “Assign to me” on unassigned chats), priority, **internal notes** (never sent to the customer) and what the channel supports.
- Close / Reopen, Pin, Mark unread and Archive are at the top of the chat. A new customer message reopens a closed chat.
- For Meta accounts, free-form replies are blocked 24 h after the customer’s last message (Meta rule). Contacts who send STOP/UNSUBSCRIBE are opted out automatically and can't be messaged.

## Contacts

- Phone numbers are stored in international format; local numbers use the company's default country code. Duplicates (same number in any format) are rejected.
- Track lead status, owner, consent, next follow-up date, tags and notes. Archive hides a contact; it can be restored.
- Export CSV respects your current filters and is audit-logged.

## Templates (Meta-approved messages)

You need an approved template to message someone first, to reply after the 24-hour window, and for every campaign.

1. **Templates → New template.** Pick the number, a name (`order_shipped`), a category and a language.
   - **Marketing:** offers, festivals, announcements.
   - **Utility:** order, payment and delivery updates for something the customer did. Meta checks the category.
2. Optional header: text (one `{{1}}` allowed) or an image, video or document. Media headers need a sample file and the Meta App ID on the account.
3. Write the body. **+ Add variable** inserts `{{1}}`, `{{2}}`…, and you give an example for each. **B** / *I* / ~S~ add WhatsApp formatting.
4. Optional footer (e.g. "Reply STOP to unsubscribe") and up to 10 buttons: quick replies, up to 2 website links (a link may end with `{{1}}`), and 1 call button.
5. The phone on the right shows exactly how it will look. Click **Submit for approval**.
6. The status changes to **approved** or **rejected** (with Meta's reason) automatically. Fix a rejected template and resubmit, or **Duplicate** it.

**Sync from Meta** imports templates you created in WhatsApp Manager.

## Campaigns (broadcasts)

1. **Campaigns → New campaign:** give it a name and choose an approved template.
2. **Audience:** contacts with any of the chosen tags, minus excluded tags, by lead status, or people who messaged you recently. Leave everything empty for all contacts.
   - Tip: import a CSV with a tag (e.g. `diwali-2026`) and target that tag.
3. **Personalise:** fill each variable from contact details (first name, name, phone, email, company, city) with a fallback, or with fixed text. Media templates need the image or file.
4. **Safeguards:**
   - "Only contacts with recorded consent" is on by default. Turning it off requires a recorded confirmation.
   - The frequency cap skips anyone who got another campaign recently.
   - Sending speed is per minute.
   - Opted-out contacts are always excluded.
5. **When:** send now, or schedule a date and time.
6. **Review:** see how many people will get it, who is excluded and why, and a personalised preview. **Send a test** to your own number, then **Send now** / **Schedule campaign**.
7. While it runs, you can **Pause** (unsent messages wait), **Resume** or **Cancel** (unsent messages are dropped).
8. The **report** shows audience, sent, delivered, read, replied and failed, with reasons. It has a funnel and per-recipient status you can filter, plus **Export CSV**.
   - "Replied" counts customers who wrote back within 7 days.
   - New conversations started by a campaign stay out of the open inbox until the customer replies.

## Automations (auto-replies)

- **Welcome message:** sent once, the first time a new contact writes.
- **Out-of-office reply:** sent outside your business hours (set per weekday, in your time zone). It is not repeated to the same person within the cooldown (4 hours by default).
- **Keyword replies:** when a message contains, starts with or exactly equals a keyword, they can:
  - reply with text or a template;
  - add tags;
  - set the lead status;
  - assign the chat to a team or agent.
  - The first match by priority wins. "Contains" matches whole words (so "price" doesn't match "pricey").
- **Safety:**
  - At most 2 auto-replies per incoming message and 6 per contact per hour.
  - Nothing is ever sent to opted-out contacts.
  - Every run appears under *Recent activity*.

## Importing contacts

**Contacts → Import CSV.**
- Only a phone column is required. Columns are recognised by name: name, first/last name, email, company, city, tags, consent, lead status.
- You can add tags to everyone, update existing contacts, and mark everyone as consented (requires a confirmation that is audit-logged).
- People who opted out are never silently re-subscribed. Invalid rows are listed with the reason.

## Your account

**Security** (top-right menu): set up two-factor authentication (save the recovery codes), change password, sign out everywhere, see recent sign-in attempts.
