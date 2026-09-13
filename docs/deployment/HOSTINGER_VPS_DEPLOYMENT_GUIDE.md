# Hostinger VPS Deployment Guide — Phase 1 Customer Trial

**Authority:** [SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md) · [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md)  
**Application commit:** `31363c5`  
**Audience:** Engineer deploying Academy LMS on **Hostinger VPS** (Ubuntu) for the first customer trial.  
**Rule:** No application code changes. Do not invent Hostinger features. Do not run UAT/demo seeders against real customer PII.

| Field | Value |
|---|---|
| Customer / academy | |
| Domain | |
| Hostinger VPS plan / hostname | |
| VPS IPv4 | |
| Deployer | |
| `APP_ENV` | ☐ `uat` (Mode A + local docs) · ☐ `production` (docs upload out of scope) |

---

## 0. Hostinger product choice (read first)

| Hostinger product | Suitable for this LMS? | Why |
|---|---|---|
| **VPS** (Ubuntu, root SSH) | **Yes — required** | Full root, PHP-FPM, Nginx, MySQL, system cron, custom `public/` document root |
| **Cloud Hosting / shared / Web Hosting** | **No** (not recommended) | No full root stack control; cannot reliably run PHP 8.4 FPM + custom workers + private `storage/` layout as documented |
| **Cloud VPS / KVM VPS** branded plans with root | **Yes** if root SSH + Ubuntu is provided | Treat as VPS below |

If the customer only purchased **Cloud Hosting** (hPanel website hosting without a VPS), **upgrade to Hostinger VPS** before continuing. This guide assumes **Hostinger VPS + Ubuntu 22.04 or 24.04**.

Companions for non-Hostinger-specific detail: payment ([RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md)), email ([EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md)), workers ([WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md)), Nginx sketch (`public/nginx.conf.example`).

---

## 1. Provision Hostinger VPS

### 1.1 In hPanel

1. Log in to **hPanel** → **VPS**.  
2. Create / select the VPS; choose **Ubuntu 22.04** or **24.04** (plain OS template — not a panel template unless you intentionally manage CyberPanel yourself).  
3. Prefer at least **2 vCPU / 4 GB RAM / 40 GB+ disk** for the trial.  
4. Set a strong **root password** (shown only at setup; store in a password manager).  
5. Note **SSH Access**: IPv4, user `root`, port (default `22`).  
6. Optional: open **Browser Terminal** on the VPS Overview page to confirm login.

### 1.2 Firewall (hPanel)

**VPS → Security → Firewall** (or Settings → Firewall):

- [ ] Allow **22** (SSH) — restrict source IP if possible  
- [ ] Allow **80** (HTTP)  
- [ ] Allow **443** (HTTPS)  
- [ ] Do **not** expose **3306** (MySQL) to the public internet  

If SSH fails after firewall changes, use hPanel **reset firewall** / Browser Terminal recovery.

### 1.3 First SSH hardening (recommended)

```bash
ssh root@<VPS_IP>
# or: use hPanel Browser Terminal

adduser academy
usermod -aG sudo academy
# Install SSH key for academy; then disable password root login when ready
```

- [ ] Non-root `academy` sudo user created  
- [ ] Future deploys use `academy` (or equivalent), not day-to-day root  

---

## 2. DNS setup (Hostinger)

### Option A — Domain at Hostinger

1. hPanel → **Domains** → manage zone for the customer domain.  
2. Create **A** record: `@` → VPS IPv4.  
3. Optional: `www` **CNAME** → apex domain.  
4. Wait for propagation.

### Option B — Domain elsewhere

Point registrar **A** / **AAAA** to the Hostinger VPS IP. Keep Hostinger nameservers only if Hostinger hosts DNS.

### Verify

```bash
dig +short <customer-domain>
ping -c 2 <customer-domain>
```

- [ ] Domain resolves to this VPS IP  
- [ ] Canonical hostname chosen for `APP_URL` (e.g. `https://learn.customer.example`)

---

## 3. PHP 8.4 installation (Ubuntu on Hostinger VPS)

Connect as root or `academy` with sudo.

Ubuntu 24.04 may already ship PHP 8.4 packages. On 22.04 you may need [ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php) (or Hostinger’s documented PHP path). Confirm with Hostinger docs for your image if packages are missing.

```bash
sudo apt update
sudo apt install -y \
  nginx \
  git unzip curl ca-certificates \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath

php8.4 -v
php8.4 -m | grep -E 'pdo_mysql|mbstring|json|sodium|openssl|curl|fileinfo|gd|intl|zip'
```

### PHP-FPM settings (trial)

Edit `/etc/php/8.4/fpm/php.ini` or a conf.d drop-in:

| Directive | Value |
|---|---|
| `upload_max_filesize` | `10M` |
| `post_max_size` | `16M` |
| `memory_limit` | `256M` |

Pool user: set `user` / `group` to `academy` (or your deploy user) in `/etc/php/8.4/fpm/pool.d/www.conf` (or a dedicated pool).

```bash
sudo systemctl enable --now php8.4-fpm
sudo systemctl restart php8.4-fpm
```

- [ ] PHP **8.4** CLI + FPM running  
- [ ] Required extensions present (`sodium` especially)  
- [ ] Upload limits ≥ 10M / 16M  

---

## 4. MySQL setup

```bash
sudo apt install -y mysql-server
sudo systemctl enable --now mysql
sudo mysql_secure_installation   # as appropriate for the image
```

Prefer **MySQL 8.4** when packages allow; if the image only provides 8.0, record the version and accept compatibility risk (architecture baseline is 8.4).

```sql
CREATE DATABASE academy_lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'academy'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON academy_lms.* TO 'academy'@'localhost';
FLUSH PRIVILEGES;
```

- [ ] MySQL listening on **localhost** only  
- [ ] App DB + user created  
- [ ] Credentials stored in `/etc/academy-lms/trial.env` (not in git)  

Hostinger **managed MySQL on Cloud Hosting** is a different product — do **not** mix shared-hosting DB credentials with this VPS app unless you intentionally run a remote DB and open firewall carefully (not recommended for trial).

---

## 5. Composer

```bash
curl -sS https://getcomposer.org/installer | php8.4 -- --install-dir=/usr/local/bin --filename=composer
composer --version   # expect 2.x
```

- [ ] Composer 2.x on PATH for the deploy user  

### Node (build only)

Install Node **≥22** on the VPS **or** build assets elsewhere and deploy `public/assets/vendor`:

```bash
# On build machine or VPS
npm ci
node bin/install-frontend-assets.mjs
```

---

## 6. Application deployment on the VPS

```bash
sudo mkdir -p /var/www/academy-lms /etc/academy-lms /var/log/academy-lms
sudo chown -R academy:academy /var/www/academy-lms /var/log/academy-lms

sudo -u academy -i
cd /var/www
git clone <REPO_URL> academy-lms
cd academy-lms
git fetch --prune
git checkout 31363c5
git rev-parse HEAD   # must print 31363c5

composer install --no-dev --optimize-autoloader

mkdir -p storage/logs storage/documents storage/mail storage/cache storage/sessions storage/tmp
chmod -R u+rwX,g+rX storage
```

- [ ] Code at `31363c5`  
- [ ] `composer install --no-dev` done  
- [ ] Frontend vendor assets present under `public/assets/vendor`  
- [ ] `storage/` writable by PHP-FPM user  
- [ ] **Do not** run `uat:seed`, `demo:prepare`, or demo Phinx seeders on customer data  

### Nginx site (document root = `public/`)

Use `public/nginx.conf.example` as a base. Point `root` to `/var/www/academy-lms/public`, pass PHP to `php8.4-fpm`, deny non-public trees.

```bash
sudo nginx -t
sudo systemctl enable --now nginx
sudo systemctl reload nginx
```

- [ ] HTTP serves the app (or Certbot will adjust for HTTPS next)  

### Migrations (after env file exists — §8)

```bash
cd /var/www/academy-lms
# env must be visible to this shell
php8.4 vendor/bin/phinx migrate -c phinx.php
php8.4 vendor/bin/phinx status -c phinx.php
```

- [ ] Migrations applied, including `20260906000001_wp_l10_video_content_items`  

---

## 7. SSL on Hostinger VPS

### Recommended: Certbot + Nginx

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d <customer-domain> -d www.<customer-domain>   # adjust SANs
sudo systemctl reload nginx
```

### Alternative

- Install SSL via a Hostinger VPS control panel template (e.g. CyberPanel Let’s Encrypt) **only if** that panel is how you manage vhosts — keep document root on `…/public`.  
- Or use Hostinger DNS + external cert; still terminate TLS on this VPS or a reverse proxy.

### After TLS

- [ ] `https://<domain>` loads with valid certificate  
- [ ] HTTP redirects to HTTPS  
- [ ] Set `APP_URL=https://<domain>`, `FORCE_HTTPS=true`, `SESSION_COOKIE_SECURE=true`  
- [ ] Reload PHP-FPM after env change  

---

## 8. Environment variables (Hostinger VPS)

Create `/etc/academy-lms/trial.env` (`chmod 600`, owner root; readable by `academy` / PHP-FPM).

**Wire into PHP-FPM** (example approaches):

- `EnvironmentFile=` in a systemd override for `php8.4-fpm`, **or**  
- `env[VAR] = value` / `clear_env = no` patterns in the pool file, **or**  
- For `APP_ENV=uat`, a project `.env` may be loaded by the app (dotenv allowed for uat) — still keep secrets off the web root and out of git.

**Wire into cron** with the same file (wrapper script that `set -a; source /etc/academy-lms/trial.env; set +a`).

### Minimum checklist (trial)

| Area | Variables |
|---|---|
| App | `APP_ENV`, `APP_URL`, `APP_DEBUG=false`, `FORCE_HTTPS=true`, `SESSION_COOKIE_SECURE=true` |
| Security | `RATE_LIMIT_PEPPER`, `TOKEN_PEPPER`, `OTP_PEPPER`, `NOTIFICATION_DELIVERY_KEY`, `TERMS_VERSION`, `PRIVACY_VERSION`, `ALLOW_LOCAL_BOOTSTRAP_ADMIN=false` |
| DB | `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_CHARSET=utf8mb4` |
| Branding | `ACADEMY_NAME`, `ACADEMY_LOGO_URL`, `ACADEMY_PRIMARY_COLOR`, `ACADEMY_SUPPORT_EMAIL`, `ACADEMY_CERTIFICATE_ISSUER_NAME` |
| Razorpay | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `PAYMENTS_FAKE_GATEWAY=0` |
| Email | `MAIL_DRIVER=smtp\|ses`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Docs (`uat` trial) | `DOCUMENTS_STORAGE_DRIVER=local`, `DOCUMENTS_LOCAL_BASE_PATH`, `DOCUMENTS_LOCAL_SIGNING_SECRET`, `DOCUMENTS_FAKE_SCANNER=1` |
| Logs | `LOG_LEVEL=info`, `LOG_PATH=storage/logs/app.log` |

Full tables: [SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md) §4 · tick-list: [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md) §6.

**Trial posture reminder:** Prefer `APP_ENV=uat` with real Razorpay + real SMTP + explicit local documents + fake scanner. `APP_ENV=production` forbids local document storage and there is **no S3 adapter** in-repo.

- [ ] Env file created and secured  
- [ ] PHP-FPM and CLI/cron see the same values  
- [ ] `PAYMENTS_FAKE_GATEWAY=0`  

---

## 9. Cron workers on Hostinger VPS

Hostinger **VPS** uses normal Linux cron (not Cloud Hosting’s hPanel cron UI).

```bash
sudo apt install -y cron
sudo systemctl enable --now cron
sudo crontab -u academy -e
```

Example (adjust PHP path; source env in a wrapper if needed):

```cron
MAILTO=""
*/1 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php payment:webhook-process >> /var/log/academy-lms/webhook.log 2>&1
*/1 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php outbox:relay >> /var/log/academy-lms/outbox.log 2>&1
*/1 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php notification:deliver >> /var/log/academy-lms/notification.log 2>&1
*/1 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php document:scan >> /var/log/academy-lms/document-scan.log 2>&1
*/5 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php payment:reconcile >> /var/log/academy-lms/reconcile.log 2>&1
*/5 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php document:stuck-scan >> /var/log/academy-lms/stuck-scan.log 2>&1
*/5 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php session:cleanup >> /var/log/academy-lms/session-cleanup.log 2>&1
*/5 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php rate-limit:cleanup >> /var/log/academy-lms/rate-limit-cleanup.log 2>&1
0 * * * * cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php token-confirmation:cleanup >> /var/log/academy-lms/token-cleanup.log 2>&1
```

- [ ] Cron installed for deploy user  
- [ ] Logs under `/var/log/academy-lms/` growing  
- [ ] Manual test: `php8.4 bin/jobs.php payment:webhook-process` exits 0  
- [ ] Never schedule `uat:seed` / `demo:process`  

### Razorpay webhook (after HTTPS)

Dashboard URL: `https://<domain>/webhooks/razorpay` → copy secret into `RAZORPAY_WEBHOOK_SECRET` → reload FPM.

### SMTP

Configure SES/SMTP vars; ensure `outbox:relay` + `notification:deliver` run every minute.

---

## 10. Branding on Hostinger

- [ ] Upload logo to `public/assets/brand/` **or** use HTTPS CDN URL  
- [ ] Set `ACADEMY_*` env vars  
- [ ] Reload PHP-FPM  
- [ ] Check login + header branding  

---

## 11. Deployment verification

### 11.1 Health

```bash
curl -sS https://<domain>/health/live
curl -sS https://<domain>/health/ready
cd /var/www/academy-lms && git rev-parse HEAD   # 31363c5
```

- [ ] `/health/live` → 200  
- [ ] `/health/ready` → 200  
- [ ] Commit is `31363c5`  

### 11.2 Functional smoke (before customer access)

- [ ] Admin / Course Admin can sign in  
- [ ] Course + past-start batch published  
- [ ] Video lesson renders  
- [ ] Razorpay test payment → webhook worker → Admitted + Active enrolment  
- [ ] Email received via SMTP  
- [ ] Certificate + public verify works  
- [ ] Document path tested **if** using `uat`/local/fake posture  
- [ ] Hostinger VPS snapshot / backup noted (hPanel snapshots + `mysqldump`)  

Full acceptance: [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md) §12.

### Sign-off

| Role | Name | Date | Pass? |
|---|---|---|---|
| Deployer | | | ☐ |
| Facilitator | | | ☐ |
| Product Owner | | | ☐ |

---

## 12. Hostinger-specific pitfalls

| Pitfall | Mitigation |
|---|---|
| Deploying on **Cloud Hosting** instead of VPS | Move to VPS; shared hosting cannot meet this stack |
| Document root set to repo root | Must be `…/academy-lms/public` |
| MySQL exposed publicly | Bind localhost; firewall deny 3306 |
| Cron without env | Workers fail closed on missing peppers/keys — source `trial.env` |
| Skipping webhook cron | Payments stick on “Confirming payment…” |
| Using fake Razorpay on customer host | Keep `PAYMENTS_FAKE_GATEWAY=0` |
| `APP_ENV=production` + local documents | Validator forbids; no S3 driver in-repo |
| UAT seed on customer DB | Contaminates real PII — do not run |

---

## Quick command index

| Step | Command / place |
|---|---|
| SSH | hPanel → VPS → Terminal **or** `ssh root@<IP>` |
| PHP | `php8.4-fpm` / `php8.4` |
| Migrate | `php8.4 vendor/bin/phinx migrate -c phinx.php` |
| SSL | `certbot --nginx -d <domain>` |
| Workers | `crontab -u academy -e` → `bin/jobs.php …` |
| Health | `curl https://<domain>/health/ready` |

---

*Hostinger VPS adaptation of the Phase 1 single-customer guides. No application code changes.*
