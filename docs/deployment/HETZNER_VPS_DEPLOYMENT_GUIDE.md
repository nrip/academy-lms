# Hetzner VPS Deployment Guide — Phase 1 Customer Trial

**Authority:** [SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md) · [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md)  
**Application commit:** `31363c5`  
**Audience:** Engineer deploying Academy LMS on **Hetzner Cloud** (VPS / Cloud Server) Ubuntu for the first customer trial.  
**Rule:** No application code changes. Do not invent Hetzner features. Do not run UAT/demo seeders against real customer PII.

| Field | Value |
|---|---|
| Customer / academy | |
| Domain | |
| Hetzner project / server name | |
| Server IPv4 | |
| Deployer | |
| `APP_ENV` | ☐ `uat` (Mode A + local docs) · ☐ `production` (docs upload out of scope) |

---

## 0. Hetzner product choice (read first)

| Hetzner product | Suitable for this LMS? | Why |
|---|---|---|
| **Hetzner Cloud Server** (CX / CPX / CAX) with root SSH | **Yes — required** | Full root, PHP-FPM, Nginx, MySQL, system cron, custom `public/` document root |
| **CX22 / similar (~2–4 GB)** | **Yes** (trial baseline) | Prefer **≥ 2 vCPU / 4 GB RAM / 40 GB+** when budget allows; avoid the smallest 2 GB-only plans if MySQL + Dompdf share the host |
| **Hetzner Cloud Managed Database (MySQL)** | **Optional** | Supported with private network / firewall rules; trial default below keeps MySQL **on the same server** |
| **Object Storage** | **Not a substitute for app storage today** | Phase 1 has **no S3 adapter** in-repo; do not claim production document storage via Hetzner Object Storage until `PR-S3` lands |
| **Storage Box** | **Backup target only** | Useful for `mysqldump` off-box; not application object storage |
| **Dedicated / Robot auction servers** | **Possible but out of scope here** | Same Ubuntu stack applies; this guide uses **Hetzner Cloud Console** |
| **App / one-click marketplace images** | **Avoid unless you own the stack** | Prefer plain **Ubuntu 22.04 / 24.04 / 26.04** so document root and **PHP 8.4** match this repo |

This guide assumes a **Hetzner Cloud Server + Ubuntu 22.04, 24.04, or 26.04 LTS** (including **26.04.1**), root (or sudo) SSH, and Cloud Firewall (or host `ufw`) locking down MySQL.

**Ubuntu 26.04 note:** Distro default PHP is often **8.5**. Academy LMS Phase 1 is certified for **PHP 8.4** (`composer.json`: `php ^8.4`; AGENTS stack = 8.4). Install and run **`php8.4` / `php8.4-fpm`** — do not use unversioned `php` / `php-fpm` packages on 26.04.

Companions for non-Hetzner-specific detail: payment ([RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md)), email ([EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md)), workers ([WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md)), Nginx sketch (`public/nginx.conf.example`).

---

## 1. Provision Hetzner Cloud Server

### 1.1 In Hetzner Cloud Console

1. Log in to [Hetzner Cloud Console](https://console.hetzner.cloud/) → select or create a **Project**.  
2. **Servers** → **Add Server**.  
3. **Location:** choose region closest to the customer / any data-residency preference (e.g. Falkenstein, Nuremberg, Helsinki, or other available locations).  
4. **Image:** Ubuntu **22.04**, **24.04**, or **26.04** LTS (plain OS — not a third-party app image unless you intentionally manage that stack). **26.04.1** is acceptable; still pin PHP to **8.4** (§3).  
5. **Type:** prefer at least **2 vCPU / 4 GB RAM / 40 GB+** (CX/CPX class as available in the region).  
6. **Networking:** attach a public IPv4 (and IPv6 if used). Optional: create a **private network** if you plan Managed Database later.  
7. **SSH Keys:** add the deploy public key (preferred over password-only).  
8. Optional: enable **Backups** or plan **Snapshots** for the trial host.  
9. Create; note **IPv4** (and IPv6 if used).  
10. Recovery: open **Console** (web VNC) from the server detail page if SSH is blocked.

### 1.2 Cloud Firewall (recommended)

**Firewalls** → create a firewall → apply to this server:

| Rule | Action | Ports | Sources |
|---|---|---|---|
| SSH | Allow | **22/tcp** | Prefer restricted CIDR (office/VPN), not `0.0.0.0/0` if possible |
| HTTP | Allow | **80/tcp** | Anywhere (for ACME + redirect) |
| HTTPS | Allow | **443/tcp** | Anywhere |
| MySQL | **Do not** allow **3306** publicly | — | App uses localhost (or private network only if using Managed DB) |
| Default inbound | Deny | — | — |

Alternatively configure host `ufw` with the same ports after first login. If SSH locks you out, use the **Console** to recover.

### 1.3 First SSH hardening (recommended)

```bash
ssh root@<HETZNER_IP>
# recovery: Cloud Console → Server → Console

adduser academy
usermod -aG sudo academy
# Install SSH key for academy; then disable password root login when ready
timedatectl set-timezone UTC   # preferred for logs / workers
```

- [ ] Non-root `academy` sudo user created  
- [ ] Future deploys use `academy` (or equivalent), not day-to-day root  
- [ ] Hostname / UTC time confirmed  

---

## 2. DNS setup

### Option A — DNS at your registrar / external DNS

Point **A** (and **AAAA** if used) to the Hetzner server public IP. Hetzner Cloud does not require using Hetzner for DNS.

### Option B — DNS via a Hetzner-related DNS product you already use

If you manage zones elsewhere (Cloudflare, registrar, etc.), keep that as source of truth; only the **A/AAAA** targets must be this server’s IPs.

### Verify

```bash
dig +short <customer-domain>
ping -c 2 <customer-domain>
```

- [ ] Domain resolves to this Hetzner IP  
- [ ] Canonical hostname chosen for `APP_URL` (e.g. `https://learn.customer.example`)

---

## 3. PHP 8.4 installation (Ubuntu on Hetzner)

Connect as root or `academy` with sudo.

Confirm the OS first:

```bash
lsb_release -a
# Expect Ubuntu 22.04, 24.04, or 26.04.x (e.g. 26.04.1 LTS)
```

### 3.1 Make `php8.4` packages available

| Ubuntu | How to get PHP 8.4 |
|---|---|
| **22.04 / 24.04** | Often via [ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php) if the archive has no `php8.4-*` |
| **26.04 / 26.04.1** | Default archive PHP is typically **8.5**. Prefer **versioned** `php8.4-*` from APT if present; otherwise use Ondřej Surý’s [packages.sury.org](https://packages.sury.org/php/) (Launchpad `ppa:ondrej/php` may not publish for `resolute`) |

**Ubuntu 26.04 — Sury (when `apt-cache policy php8.4` has no candidate):**

```bash
sudo apt update
sudo apt install -y ca-certificates curl lsb-release
sudo curl -fsSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
sudo sh -c 'printf "%s\n" \
  "Types: deb" \
  "URIs: https://packages.sury.org/php/" \
  "Suites: $(lsb_release -sc)" \
  "Components: main" \
  "Signed-By: /usr/share/keyrings/deb.sury.org-php.gpg" \
  > /etc/apt/sources.list.d/php.sources'
sudo apt update
apt-cache policy php8.4   # must show a 8.4.x candidate
```

**Ubuntu 22.04 / 24.04 — ondrej PPA (common path):**

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
```

Skip third-party repos if `php8.4-fpm` is already installable from Ubuntu archives.

### 3.2 Install Nginx + PHP 8.4 FPM/CLI

```bash
sudo apt install -y \
  nginx \
  git unzip curl ca-certificates \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath

php8.4 -v    # must be 8.4.x — not 8.5.x
php8.4 -m | grep -E 'pdo_mysql|mbstring|json|sodium|openssl|curl|fileinfo|gd|intl|zip'
```

On Ubuntu 26.04, also confirm the default CLI is not silently 8.5:

```bash
php -v || true
# Prefer explicit /usr/bin/php8.4 in cron and Composer (this guide already does)
sudo update-alternatives --set php /usr/bin/php8.4   # only if alternatives are registered
```

### 3.3 PHP-FPM settings (trial)

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

Prefer **MySQL 8.4** when packages allow (architecture baseline). Older Ubuntu images often ship **8.0**; on **26.04** you may get 8.4 from archives — confirm with `mysql --version`. If only 8.0 is available, record the version and accept untested compatibility risk.

### 4.1 On-server MySQL (recommended for trial)

```bash
sudo apt install -y mysql-server
sudo systemctl enable --now mysql
sudo mysql_secure_installation   # as appropriate for the image
mysql --version
```

```sql
CREATE DATABASE academy_lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'academy'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON academy_lms.* TO 'academy'@'localhost';
FLUSH PRIVILEGES;
```

- [ ] MySQL listening on **localhost** only (confirm Cloud Firewall does not expose 3306)  
- [ ] App DB + user created  
- [ ] Credentials stored in `/etc/academy-lms/trial.env` (not in git)  

### 4.2 Optional: Hetzner Cloud Managed MySQL

If using Managed Database instead of local MySQL:

- [ ] Create MySQL database in the **same region**; prefer **private network** attachment  
- [ ] Allow this server’s private IP (or network) in the DB firewall — **not** `0.0.0.0/0`  
- [ ] Create `academy_lms` + user with utf8mb4  
- [ ] Set `DB_HOST` to the managed hostname; keep `DB_CHARSET=utf8mb4`  
- [ ] Still **deny** public 3306 on the app server  

---

## 5. Composer

```bash
curl -sS https://getcomposer.org/installer | php8.4 -- --install-dir=/usr/local/bin --filename=composer
composer --version   # expect 2.x
```

- [ ] Composer 2.x on PATH for the deploy user  

### Node (build only)

Install Node **≥22** on the Hetzner server **or** build assets elsewhere and deploy `public/assets/vendor`:

```bash
# On build machine or Hetzner server
npm ci
node bin/install-frontend-assets.mjs
```

---

## 6. Application deployment on the Hetzner server

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

Use `public/nginx.conf.example` as a base. Point `root` to `/var/www/academy-lms/public`, pass PHP to `php8.4-fpm`, deny non-public trees (`src`, `config`, `database`, `tests`, `storage`, `vendor`, `templates`, `bin`).

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
- [ ] Optional: `php8.4 bin/setup.php` only on empty/new DB — **without** `--seed-uat`  

Create Admin / Course Admin via an approved bootstrap for this env (`ALLOW_LOCAL_BOOTSTRAP_ADMIN=false` on the public host).

---

## 7. SSL on Hetzner VPS

Hetzner Cloud does not terminate HTTPS for a plain Cloud Server by default — issue certificates on the instance (or terminate at a reverse proxy / load balancer you control).

### Recommended: Certbot + Nginx

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d <customer-domain> -d www.<customer-domain>   # adjust SANs
sudo systemctl reload nginx
```

Confirm renewals:

```bash
sudo systemctl status certbot.timer   # or: sudo certbot renew --dry-run
```

### Alternative

- Terminate TLS on a reverse proxy or load balancer in front of the Hetzner server; set `TRUSTED_PROXIES` and forward `X-Forwarded-Proto`.  
- Still keep the app document root as `…/public` on the origin.

### After TLS

- [ ] `https://<domain>` loads with valid certificate  
- [ ] HTTP redirects to HTTPS  
- [ ] Set `APP_URL=https://<domain>`, `FORCE_HTTPS=true`, `SESSION_COOKIE_SECURE=true`  
- [ ] Reload PHP-FPM after env change  

---

## 8. Environment variables (Hetzner VPS)

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
| DB | `DB_HOST=127.0.0.1` (or managed hostname), `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_CHARSET=utf8mb4` |
| Branding | `ACADEMY_NAME`, `ACADEMY_LOGO_URL`, `ACADEMY_PRIMARY_COLOR`, `ACADEMY_SUPPORT_EMAIL`, `ACADEMY_CERTIFICATE_ISSUER_NAME` |
| Razorpay | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `PAYMENTS_FAKE_GATEWAY=0` |
| Email | `MAIL_DRIVER=smtp\|ses`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Docs (`uat` trial) | `DOCUMENTS_STORAGE_DRIVER=local`, `DOCUMENTS_LOCAL_BASE_PATH`, `DOCUMENTS_LOCAL_SIGNING_SECRET`, `DOCUMENTS_FAKE_SCANNER=1` |
| Logs | `LOG_LEVEL=info`, `LOG_PATH=storage/logs/app.log` |
| Outbox | `OUTBOX_TRANSPORT` configured for the mail path |

Full tables: [SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md) §4 · tick-list: [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md) §6.

**Trial posture reminder:** Prefer `APP_ENV=uat` with real Razorpay + real SMTP + explicit local documents + fake scanner. `APP_ENV=production` forbids local document storage and there is **no S3 adapter** in-repo (Hetzner Object Storage does not close that gap by itself).

- [ ] Env file created and secured  
- [ ] PHP-FPM and CLI/cron see the same values  
- [ ] `PAYMENTS_FAKE_GATEWAY=0`  

---

## 9. Cron workers on Hetzner VPS

Hetzner Cloud Servers use normal Linux cron (or systemd timers — see [PROCESS_SUPERVISION_EXAMPLES.md](../operations/PROCESS_SUPERVISION_EXAMPLES.md)). There is no panel-only cron for this stack.

```bash
sudo apt install -y cron
sudo systemctl enable --now cron
sudo crontab -u academy -e
# or: /etc/cron.d/academy-lms owned by root
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
- [ ] Workers inherit same secrets as PHP-FPM  
- [ ] Logs under `/var/log/academy-lms/` growing  
- [ ] Manual test: `php8.4 bin/jobs.php payment:webhook-process` exits 0  
- [ ] Never schedule `uat:seed` / `demo:process`  
- [ ] Nightly `mysqldump` (+ checksum) scheduled; optional copy to Storage Box; Hetzner Backups/Snapshots noted if enabled  

### Razorpay webhook (after HTTPS)

Dashboard URL: `https://<domain>/webhooks/razorpay`  
Events: at least `payment.captured`, `payment.failed` (recommended: `order.paid`, `payment.authorized`)  
Copy secret → `RAZORPAY_WEBHOOK_SECRET` → reload FPM.

Architecture: browser return is informational; `payment:webhook-process` drives success/admit.

### SMTP

Configure SES/SMTP vars; ensure `outbox:relay` + `notification:deliver` run every minute. Details: [EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md).

---

## 10. Branding on Hetzner

- [ ] Upload logo to `public/assets/brand/` **or** use HTTPS CDN URL  
- [ ] Set `ACADEMY_*` env vars  
- [ ] Reload PHP-FPM  
- [ ] Check login + header branding  

---

## 11. Deployment verification

### 11.1 Platform health

```bash
curl -sS https://<domain>/health/live
curl -sS https://<domain>/health/ready
cd /var/www/academy-lms && git rev-parse HEAD   # 31363c5
php8.4 -v
php8.4 -m | grep -E 'pdo_mysql|mbstring|json|sodium|openssl|curl|gd'
php8.4 vendor/bin/phinx status -c phinx.php
```

- [ ] Domain works over HTTPS; certificate valid  
- [ ] `/health/live` → 200  
- [ ] `/health/ready` → 200  
- [ ] Commit is `31363c5`  
- [ ] Nginx document root is `…/public` only  

### 11.2 Functional smoke (before customer access)

- [ ] Admin / Course Admin can sign in  
- [ ] Course + past-start batch published  
- [ ] Video lesson renders  
- [ ] Razorpay test payment → webhook worker → Admitted + Active enrolment  
- [ ] Email received via SMTP  
- [ ] Certificate + public verify works  
- [ ] Document path tested **if** using `uat`/local/fake posture  
- [ ] Finance SoD: finance cannot open document URLs (if docs in scope)  
- [ ] Backup: Hetzner snapshot/Backups and/or `mysqldump` rehearsed once  

Full acceptance: [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md) §12.

### Sign-off

| Role | Name | Date | Pass? |
|---|---|---|---|
| Deployer | | | ☐ |
| Facilitator | | | ☐ |
| Product Owner | | | ☐ |

---

## 12. Hetzner-specific pitfalls

| Pitfall | Mitigation |
|---|---|
| Undersized Cloud Server for MySQL + PHP | Prefer ≥ 2–4 GB RAM for PHP-FPM + MySQL + Dompdf |
| Document root set to repo root | Must be `…/academy-lms/public` |
| Cloud Firewall opens **3306** | Keep MySQL localhost or private network only |
| Cron without env | Workers fail closed on missing peppers/keys — source `trial.env` |
| Skipping webhook cron | Payments stick on “Confirming payment…” |
| Using fake Razorpay on customer host | Keep `PAYMENTS_FAKE_GATEWAY=0` |
| Assuming Hetzner Object Storage = production docs | No S3 adapter in-repo; `production` + local storage is forbidden |
| `APP_ENV=production` + local documents | Validator forbids; no S3 driver in-repo |
| UAT seed on customer DB | Contaminates real PII — do not run |
| SSH lockout after firewall change | Use Cloud Console **Console** (VNC) to recover |
| Marketplace / one-click image with wrong document root | Rebuild on plain Ubuntu or fix vhost to `public/` |
| Ubuntu **26.04** + unversioned `php` / `php-fpm` | Installs **8.5** by default — use **`php8.4-*`** and point Nginx/cron at `php8.4-fpm` / `php8.4` |
| Assuming 26.04 is unsupported | **26.04.1 LTS is fine** for the OS; the hard requirement is still **PHP 8.4**, MySQL utf8mb4, and `public/` document root |

---

## Quick command index

| Step | Command / place |
|---|---|
| SSH | `ssh root@<IP>` or Cloud Console → **Console** |
| PHP | `php8.4-fpm` / `php8.4` |
| Migrate | `php8.4 vendor/bin/phinx migrate -c phinx.php` |
| SSL | `certbot --nginx -d <domain>` |
| Workers | `crontab -u academy -e` → `bin/jobs.php …` |
| Health | `curl https://<domain>/health/ready` |
| Firewall | Cloud Console → **Firewalls** |

---

*Hetzner Cloud VPS adaptation of the Phase 1 single-customer guides. No application code changes.*
