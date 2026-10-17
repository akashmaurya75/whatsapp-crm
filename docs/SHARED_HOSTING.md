# Installing on shared Linux hosting

Works on cPanel, Hostinger (hPanel), DirectAdmin, Plesk, CyberPanel and similar Linux hosts running Apache or LiteSpeed.
You don't need SSH, Composer or a background service: everything is done with the File Manager, the database page
and one cron job. It takes about 10 minutes.

## What you need

- A **subdomain** for the app, such as `crm.yourcompany.com`. The app must run at the top of a domain or subdomain, not in
  a sub-folder like `yourcompany.com/crm`.
- **PHP 8.3 or newer** with these extensions: `curl`, `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`. The `sodium` extension is
  recommended but optional. The installer checks all of these and tells you where to switch them on.
- A **MySQL 5.7.8+ / MariaDB 10.3+** database. MySQL 8 or MariaDB 10.6+ is recommended.
- An **SSL certificate** for the subdomain (free AutoSSL / Let's Encrypt in every panel). Meta requires https for webhooks
  and "Continue with Facebook".

## Step 1: Prepare the subdomain and PHP

1. Create the subdomain in your panel:
   - **cPanel:** *Domains*.
   - **Hostinger:** *Domains → Subdomains*.
   - **DirectAdmin:** *Subdomain Management*.
   - **Plesk:** *Add Subdomain*.

   You'll set its folder in step 3.
2. Turn on SSL for it (cPanel: *SSL/TLS Status → Run AutoSSL*; Hostinger: *Security → SSL*).
3. Set PHP 8.3 for the subdomain:
   - **cPanel:** *MultiPHP Manager* or *Select PHP Version*.
   - **Hostinger:** *Advanced → PHP Configuration*.

   On the *Extensions* tab, tick `curl`, `mbstring`, `pdo_mysql` (on CloudLinux, `nd_pdo_mysql`), `fileinfo` and `sodium`.

## Step 2: Create the database

In *MySQL Databases* (cPanel) or *Databases* (Hostinger):

1. Create a database, e.g. `crm`. The panel adds your account prefix, so it becomes something like `cpuser_crm`.
2. Create a user with a strong password.
3. **Add the user to the database with ALL PRIVILEGES.** On cPanel this is a separate step that people often miss.

Write down the full database name, the user name and the password. The host is `localhost`.

## Step 3: Upload the files

Upload `whatsapp-crm.zip` with the File Manager and **Extract** it. Choose the layout that matches your panel.

### A. cPanel, DirectAdmin, Plesk (recommended)

1. Upload the zip to your **home folder** (the folder that contains `public_html`, not `public_html` itself) and extract it.
   This creates `/home/USER/whatsapp-crm`.
2. Set the subdomain's **document root** to `whatsapp-crm/public`:
   - **cPanel:** *Domains → Manage*.
   - **DirectAdmin:** *Custom document root*.
   - **Plesk:** *Hosting Settings*.

Only the `public` folder is reachable from the web. Your settings, the database password and the source code are not.

### B. Hostinger, and hosts that only allow folders inside `public_html`

1. Upload the zip to your **home folder** and extract it. On Hostinger this is `/home/u123456789/domains/yourcompany.com`, the folder that contains `public_html`.
2. Open the subdomain's folder, e.g. `public_html/crm`. Delete any `default.php` or `index.html` the host put there.
3. Copy **everything inside** `whatsapp-crm/public` into it: `index.php`, `.htaccess` and the `assets` folder. In the File Manager,
   turn on *Show hidden files* so `.htaccess` is included.

`index.php` finds the app folder automatically; it looks in the parent folders for `whatsapp-crm`. If you named or placed it
differently, edit `index.php` and set `$appDir = '/full/path/to/whatsapp-crm';`.

### C. You can only use the subdomain's folder

Extract the zip directly into the subdomain's folder. The included `.htaccess` files serve only `public/` and block
`.env`, `storage/` and the source code. The installer **tests this protection** and stops if your server ignores it
(some Nginx-only hosts do). In that case use layout A or B.

## Step 4: Run the installer

1. Open `https://crm.yourcompany.com`.
2. It asks for an **installer key**. In the File Manager, open `whatsapp-crm/storage/install-key.txt` and copy the 12-character code.
   This proves you own the hosting account.
3. **Check:** fix anything marked ✕. Every message tells you where the setting is, and you can reload as often as you like.
4. **Database:** choose MySQL and enter `localhost`, the full database name, the user and the password.
5. **Accounts:** choose **Shared hosting**, and confirm the address and your company and admin logins.
6. **Done:** the installer locks itself and shows your cron command.

## Step 5: Add the cron job (one line)

The cron job sends campaigns, processes incoming messages and does the maintenance. Create it here:

- **cPanel:** *Cron Jobs*.
- **Hostinger:** *Advanced → Cron Jobs*.
- **DirectAdmin:** *Cronjobs*.
- **Plesk:** *Scheduled Tasks*.

Set it to run **every minute** (`* * * * *`) with the command from the last installer page. It looks like this:

```
/opt/alt/php83/usr/bin/php /home/USER/whatsapp-crm/bin/console cron --quiet
```

- **Use the full PHP 8.3 path.** On many hosts plain `php` in cron is an older version, and you'll get an email saying "needs PHP 8.3".
  The installer shows the paths it found. Common ones:

  | Panel | PHP 8.3 path |
  |---|---|
  | cPanel (EasyApache) | `/opt/cpanel/ea-php83/root/usr/bin/php` |
  | CloudLinux / Hostinger / Namecheap / A2 | `/opt/alt/php83/usr/bin/php` |
  | Plesk | `/opt/plesk/php/8.3/bin/php` |
  | DirectAdmin | `/usr/local/php83/bin/php` |

- **Hostinger's form** has a fixed `php` in front: choose *PHP* as the command type and enter only `/home/u123…/domains/…/whatsapp-crm/bin/console cron --quiet`.
- **Overlap is safe:** each run works for about 50 seconds and only one runs at a time.
- **Checking it:** *Admin → System health* shows "Background work (cron): Running" and when it last ran.

### No cron, or a panel that can only call a URL

The `.env` file contains a secret `CRON_KEY`. Call this address every minute, from your panel or a free service such as cron-job.org:

```
https://crm.yourcompany.com/cron?key=YOUR_CRON_KEY
```

Keep it private. Change `CRON_KEY` in `.env` if it leaks, or set it empty to switch the web cron off.

### Between cron runs

On shared hosting, the installer sets `QUEUE_MODE=inline`. After a page is sent to a visitor (and after every incoming WhatsApp
webhook), the same PHP process also handles waiting messages, for up to 20 seconds. Replies and incoming messages therefore
appear within seconds, even though cron runs only once a minute. This works on LiteSpeed (most shared hosts) and PHP-FPM, and
the visitor never waits for it.

## Step 6: Connect WhatsApp

See [WHATSAPP_SETUP.md](WHATSAPP_SETUP.md). The webhook address is `https://crm.yourcompany.com/webhooks/meta`, or the
per-account URL shown on the account page.

## Updating to a new version

1. **Back up** the database (*phpMyAdmin → Export*) and the `.env` file. Without `.env` and its `APP_KEY`, the saved WhatsApp tokens can't be decrypted.
2. Upload the new zip and extract it over `whatsapp-crm`. It never contains `.env` or anything in `storage/`, so your settings stay.
3. Layout B only: copy `whatsapp-crm/public/assets` (and `index.php`) into the subdomain folder again.
4. The next cron run applies database updates automatically. To apply them sooner, add a one-off cron job `… bin/console migrate`.

## Troubleshooting

| Problem | Fix |
|---|---|
| "500 Internal Server Error" right after uploading | Make sure the folders are permission **755** and the files **644**. Files must not be group/world-writable (775/777): suPHP/LiteSpeed refuse them |
| "The requested resource /install was not found on this server" | That message comes from PHP's built-in test server (`php -S`), not your hosting. On shared hosting don't run `php -S`; open `https://crm.yourcompany.com` in the browser |
| The host's default page shows instead of the installer | Delete `default.php` / `index.html` from the subdomain folder |
| "The application folder was not found" | Layout B: put `whatsapp-crm` in a parent folder of the subdomain folder, or set `$appDir` in `index.php` |
| "must be installed at the root of a domain or subdomain" | Create a subdomain and open the app there instead of a sub-folder |
| "Private files … cannot be downloaded ✕" | Your server ignores `.htaccess`: use layout A or B |
| MySQL "Access denied" | Use the full prefixed names (`cpuser_crm`), host `localhost`, and add the user to the database with ALL PRIVILEGES |
| Endless redirect after installing | Cloudflare "Flexible" SSL. Switch Cloudflare to **Full**, or set `APP_FORCE_HTTPS=false` in `.env` |
| Messages stay "queued" | The cron job isn't running. Check the PHP path, then *Admin → System health* |
| Cron email "needs PHP 8.3" | Use the full PHP 8.3 path from the table above |
| Logs | `whatsapp-crm/storage/logs/` (open with the File Manager) |

## Security notes for shared hosting

- After installing, `.env` has permission 640. Don't make it 644 or 666.
- Turn on two-factor authentication for the platform admin and company owners (top-right menu → Security).
- The installer can't run again once installed. Its key file is deleted after installing.
- Resource limits: shared plans usually cap CPU and processes. Keep campaign sending speed moderate (e.g. 60–120 per
  minute) unless your plan is generous. Each cron run handles as many messages as it can in 50 seconds.
