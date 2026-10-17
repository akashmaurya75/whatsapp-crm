# Deployment guide (Ubuntu 24.04 VPS)

> The quickest route is the web installer, described in [INSTALL.md](INSTALL.md). This page covers the manual route and day-2 operations (backups, upgrades, troubleshooting).

## 1. Packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml certbot python3-certbot-nginx
php -m | grep -E 'sodium|pdo_mysql|curl|mbstring'   # all four must be listed
```

## 2. Database

```sql
CREATE DATABASE whatsapp_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'whatsapp_crm'@'localhost' IDENTIFIED BY '<long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON whatsapp_crm.* TO 'whatsapp_crm'@'localhost';
```

## 3. Code and configuration

```bash
sudo mkdir -p /var/www/whatsapp-crm && sudo chown $USER /var/www/whatsapp-crm
# copy the project into /var/www/whatsapp-crm (git clone, rsync or unzip)
cd /var/www/whatsapp-crm
cp .env.example .env && chmod 600 .env
php bin/console key:generate        # put the value in APP_KEY — back it up somewhere safe
nano .env                           # APP_URL, DB_*, MAIL_*, DEFAULT_COUNTRY_CODE, TRUSTED_PROXIES
mkdir -p storage/logs storage/sessions storage/mail
sudo chown -R www-data:www-data storage .env && sudo chmod -R 750 storage
sudo -u www-data php bin/console migrate
sudo -u www-data php bin/console seed
sudo -u www-data php bin/console create-admin
```

> **APP_KEY** encrypts WhatsApp credentials and 2FA secrets. If it is lost or changed, those values cannot be decrypted and must be re-entered.

## 4. Web server and HTTPS

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/whatsapp-crm   # edit server_name + certificate paths
sudo ln -s /etc/nginx/sites-available/whatsapp-crm /etc/nginx/sites-enabled/
sudo certbot --nginx -d crm.example.com
sudo nginx -t && sudo systemctl reload nginx
```

Recommended `php.ini` (FPM): `expose_php=Off`, `display_errors=Off`, `log_errors=On`, `upload_max_filesize=2M`, `post_max_size=2M`.

## 5. Queue worker and scheduler

```bash
sudo cp deploy/whatsapp-crm-worker.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now whatsapp-crm-worker
sudo systemctl status whatsapp-crm-worker
# scheduler (maintenance + hourly account health checks)
echo '* * * * * www-data cd /var/www/whatsapp-crm && php bin/console schedule:run >> storage/logs/schedule.log 2>&1' | sudo tee /etc/cron.d/whatsapp-crm
```

The worker exits after `--max-jobs` jobs; systemd restarts it (keeps memory flat). The admin **System health** page shows the worker heartbeat.

## 6. Docker (alternative)

`docker compose up -d`, then run `migrate`, `seed` and `create-admin` with `docker compose exec app php bin/console …`. Put TLS in front of port 8080.

## Backups

Daily database dump (keep 14 days) plus the `.env` file (contains APP_KEY):

```bash
# /etc/cron.d/whatsapp-crm-backup
15 2 * * * root mysqldump --single-transaction --routines whatsapp_crm | gzip > /var/backups/wcrm-$(date +\%F).sql.gz && find /var/backups -name 'wcrm-*.sql.gz' -mtime +14 -delete
```

Copy backups off the server (object storage, another host). Store `.env`/APP_KEY separately from the dumps.

### Restore

```bash
sudo systemctl stop whatsapp-crm-worker
gunzip -c /var/backups/wcrm-2026-10-12.sql.gz | mysql whatsapp_crm
# restore the matching .env (same APP_KEY)
sudo systemctl start whatsapp-crm-worker
```

Test a restore into a scratch database at least once a quarter.

## Upgrades

```bash
cd /var/www/whatsapp-crm
mysqldump --single-transaction whatsapp_crm | gzip > /var/backups/pre-upgrade.sql.gz
sudo systemctl stop whatsapp-crm-worker
# replace code (keep .env and storage/)
sudo -u www-data php bin/console migrate
php tests/run.php                       # optional sanity check on the server
sudo systemctl start whatsapp-crm-worker && sudo systemctl reload php8.3-fpm
```

Migrations are forward-only and recorded in the `migrations` table.

## Troubleshooting

| Symptom | Check |
|---|---|
| "Service temporarily unavailable" | `storage/logs/app.log` and PHP-FPM log; usually DB credentials or missing `sodium` |
| "APP_KEY must be 32 bytes" | Run `php bin/console key:generate` and set `APP_KEY` |
| Login loops back to /login | HTTPS mismatch: behind a proxy set `TRUSTED_PROXIES`; for plain-HTTP dev set `SESSION_SECURE_COOKIE=false` |
| "Session expired" (419) on forms | Cookie blocked or `storage/sessions` not writable by www-data |
| Inbound messages don't appear | Worker running? (`systemctl status whatsapp-crm-worker`); webhook returns 401 → wrong app secret; check Meta's webhook delivery log |
| Messages stay "queued" | Worker stopped, or provider unreachable — account page shows the last error; jobs retry 4 times with backoff (10, 20, 40, 80 s) then fail |
| Messages "failed" with 131047 | 24-hour window closed; needs an approved template |
| Health page "Worker stalled" | Worker crashed or can't write `storage/worker.heartbeat` |
| Failed jobs | Admin → System health → Retry, or `php bin/console queue:retry --id=N` |
