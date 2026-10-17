# Installing WhatsApp CRM

The app installs itself. Put the files somewhere PHP can run them, open the site in a browser,
and the **installer** walks you through 4 steps:

1. **Check:** confirms PHP 8.3+ and the needed extensions, that the folders are writable, that the app is at the root of a
   (sub)domain and — when the whole app sits inside the web folder — that `.env` and the source code really can't be downloaded.
2. **Database:** MySQL/MariaDB (recommended) or SQLite (one file, zero setup). It tests the connection and creates the database if allowed.
3. **Accounts:** where you're installing (shared hosting / VPS / this computer), app address, your company and login, and a separate platform-admin login.
4. **Done:** creates the tables and your accounts, writes the `.env` settings file, then locks itself.

The installer refuses to run again once installed, and it never overwrites a database that already has users.
If you open it from another computer (a real server), it first asks for a one-time key stored in
`storage/install-key.txt`. That proves you control the server.

---

## A. Shared Linux hosting (cPanel, Hostinger, DirectAdmin, Plesk) — no SSH needed

Short version (full guide with screenshots-level detail: **[SHARED_HOSTING.md](SHARED_HOSTING.md)**):

1. Create a subdomain (e.g. `crm.yourcompany.com`) with SSL, and set PHP **8.3** with `curl`, `mbstring`, `pdo_mysql`, `fileinfo` (and `sodium` if offered).
2. Create a MySQL database + user, and add the user to the database with ALL PRIVILEGES.
3. Upload `whatsapp-crm.zip` to your home folder and extract it, then point the subdomain's document root at `whatsapp-crm/public`
   (Hostinger: copy the contents of `whatsapp-crm/public` into the subdomain's folder instead).
4. Open the subdomain, enter the key from `whatsapp-crm/storage/install-key.txt`, choose **Shared hosting** and follow the steps.
5. Add the one cron job shown on the last page (every minute): `/full/path/to/php83 /home/USER/whatsapp-crm/bin/console cron --quiet`.

## B. On a server (VPS: Ubuntu + Nginx or Apache)

```bash
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml unzip
sudo unzip whatsapp-crm.zip -d /var/www/
sudo chown -R www-data:www-data /var/www/whatsapp-crm
sudo cp /var/www/whatsapp-crm/deploy/nginx.conf /etc/nginx/sites-available/whatsapp-crm   # set server_name
sudo ln -s /etc/nginx/sites-available/whatsapp-crm /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d crm.yourcompany.com        # HTTPS
# create a MySQL database + user (or let the installer create the database if the user may):
sudo mysql -e "CREATE DATABASE whatsapp_crm CHARACTER SET utf8mb4; CREATE USER 'wcrm'@'localhost' IDENTIFIED BY 'CHANGE-ME-long-password'; GRANT ALL ON whatsapp_crm.* TO 'wcrm'@'localhost';"
sudo cat /var/www/whatsapp-crm/storage/install-key.txt   # appears after you first open the site
```

1. Open `https://crm.yourcompany.com`, enter the installer key, choose **VPS / own server** and follow the steps.
2. The final page shows the exact commands to start the background worker (systemd) and the scheduler (cron). Run them.

Prefer the terminal? `sudo -u www-data php bin/console install` asks the same questions.

## C. On your own computer, for trying it out (Linux / Mac)

```bash
# Ubuntu:  sudo apt install php8.3-cli php8.3-sqlite3 php8.3-curl php8.3-mbstring     macOS:  brew install php
unzip whatsapp-crm.zip && cd whatsapp-crm
./start.sh            # opens http://localhost:8000 → installer (choose SQLite and "This computer")
```

Or without a browser: `php bin/console setup` (SQLite + generated logins), then `php -S localhost:8000 -t public` and
`php bin/console queue:work` in a second terminal. (`start.bat` does the same on Windows.)

> **Receiving real WhatsApp messages on localhost:** Meta has to reach your computer over HTTPS. Run a tunnel
> (for example `cloudflared tunnel --url http://localhost:8000`), put the https address it prints into `APP_URL` in `.env`.

## Re-installing / starting over

The installer only runs when there is no `storage/installed.lock` and no `.env` containing an `APP_KEY`.
To start completely fresh on a test machine, delete `.env`, `storage/installed.lock` and `storage/database.sqlite`
(or empty the MySQL database), then open the site again. **Never do this on a live system:** it deletes your data and
your encryption key.

## Troubleshooting the installer

| Message | Fix |
|---|---|
| A ✕ next to a PHP extension | Enable it in `php.ini` or your hosting panel, restart the web server, then click *Check again* |
| "Folder storage is not writable" | Shared hosting: set `storage` and its sub-folders to 755 in the File Manager. VPS: `chown -R www-data storage` |
| "Can create the .env settings file" ✕ | Make the app folder writable for the install, or create an empty writable `.env` file |
| "Could not connect to MySQL" | Check host, port, user and password. On shared hosting the host is often `localhost` |
| "cannot open database" | Create the database in your hosting panel first (your user may not be allowed to create it) |
| "already contains WhatsApp CRM users" | Use a new, empty database. The installer never overwrites data |
| A blank page or a "needs PHP 8.3" message | Switch PHP to 8.3+ for that (sub)domain |
| More shared-hosting problems | See the table in [SHARED_HOSTING.md](SHARED_HOSTING.md#troubleshooting) |
| "The requested resource /install was not found on this server" | PHP's test server was started in the wrong folder. Use `./start.sh`, or `php -S localhost:8000 -t public` from the app folder. (This version also works when started in the app folder itself.) On shared hosting you don't run `php -S`: just open your (sub)domain |
| 404 on every page except `/` (Apache) | Enable `mod_rewrite` and `AllowOverride All` for the folder |
