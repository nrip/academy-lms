# Linode VPS Deployment Guide — Phase 1 Customer Trial

**Authority:** [SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md) · [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md)  
**Application commit:** `31363c5`  
**Audience:** Engineer deploying Academy LMS on **Linode** (Akamai Cloud) **VPS / Compute Instance** (Ubuntu) for the first customer trial.  
**Rule:** No application code changes. Do not invent Linode features. Do not run UAT/demo seeders against real customer PII.

| Field | Value |
|---|---|
| Customer / academy | |
| Domain | |
| Linode label / region | |
| Linode IPv4 | |
| Deployer | |
| `APP_ENV` | ☐ `uat` (Mode A + local docs) · ☐ `production` (docs upload out of scope) |

---

## 0. Linode product choice (read first)

| Linode product | Suitable for this LMS? | Why |
|---|---|---|
| **Linode Compute Instance** (Shared / Dedicated / Premium CPU) with root SSH | **Yes — required** | Full root, PHP-FPM, Nginx, MySQL, system cron, custom `public/` document root |
| **Nanode** (1 GB) | **Marginal** | Possible for a light private rehearsal only; prefer **≥ 2 GB RAM** for PHP-FPM + MySQL + Dompdf on one host |
| **Linode Managed Database (MySQL)** | **Optional** | Supported if you accept remote DB + private network / Cloud Firewall rules; trial default below keeps MySQL **on the same instance** |
| **Object Storage** | **Not a substitute for app storage today** | Phase 1 has **no S3 adapter** in-repo; do not claim production document storage via Linode Object Storage until `PR-S3` lands |
| **LKE (Kubernetes)** | **Out of scope for this trial guide** | Use a single Compute Instance for the 5-day trial |

This guide assumes a **Linode Compute Instance + Ubuntu 22.04 or 24.04 LTS**, root (or sudo) SSH, and Cloud Firewall (or host `ufw`) locking down MySQL.

Companions for non-Linode-specific detail: payment ([RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md)), email ([EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md)), workers ([WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md)), Nginx sketch (`public/nginx.conf.example`).

---

## 1. Provision Linode Compute Instance

### 1.1 In Cloud Manager

1. Log in to [Linode Cloud Manager](https://cloud.linode.com/) → **Linodes** → **Create Linode**.  
2. **Image:** Ubuntu **22.04** or **24.04** LTS (plain distribution — not a third-party marketplace app unless you intentionally own that stack).  
3. **Region:** closest to the customer / learners (latency + any data-residency preference).  
4. **Plan:** prefer at least **2 vCPU / 4 GB RAM / 40 GB+ SSD** (e.g. Shared 4 GB or Dedicated equivalent). Avoid 1 GB Nanode for a customer-facing trial.  
5. **Root password** or **SSH Keys:** add your deploy SSH public key; store root credentials in a password manager if used.  
6. Optional: enable **Backups** (Linode Backup Service) for the trial host.  
7. Create; note **IPv4** (and IPv6 if used).  
8. Optional recovery: **Launch LISH Console** (browser) from the Linode detail page if SSH is blocked.

### 1.2 Cloud Firewall (recommended)

**Firewalls** → create a firewall → assign to this Linode:

| Rule | Action | Ports | Sources |
|---|---|---|---|
| SSH | Accept | **22/tcp** | Prefer restricted CIDR (office/VPN), not `0.0.0.0/0` if possible |
| HTTP | Accept | **80/tcp** | Anywhere (for ACME + redirect) |
| HTTPS | Accept | **443/tcp** | Anywhere |
| MySQL | **Do not** open **3306** publicly | — | App uses localhost (or private VPC only if using Managed DB) |
| Default inbound | Drop / deny | — | — |

Alternatively configure host `ufw` with the same ports after first login. If SSH locks you out, use **LISH** to recover.

### 1.3 First SSH hardening (recommended)

```bash
ssh root@<LINODE_IP>
# recovery: Cloud Manager → Linode → Launch LISH Console

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

### Option A — Domain DNS at Linode

1. Cloud Manager → **Domains** → create or open the zone.  
2. **A** record: `@` (or hostname) → Linode IPv4.  
3. Optional: **AAAA** → Linode IPv6.  
4. Optional: `www` **CNAME** → apex / canonical hostname.  
5. Wait for propagation.

### Option B — Domain elsewhere

Point registrar **A** / **AAAA** to the Linode IPv4/IPv6. Keep Linode nameservers only if Linode hosts DNS.

### Verify

```bash
dig +short <customer-domain>
ping -c 2 <customer-domain>
```

- [ ] Domain resolves to this Linode IP  
- [ ] Canonical hostname chosen for `APP_URL` (e.g. `https://learn.customer.example`)

---

## 3. PHP 8.4 installation (Ubuntu on Linode)

Connect as root or `academy` with sudo.

Ubuntu 24.04 may already ship PHP 8.4 packages. On 22.04 you typically need [ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php):

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

sudo apt install -y \
  nginx \
  git unzip curl ca-certificates \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath

php8.4 -v
php8.4 -m | grep -E 'pdo_mysql|mbstring|json|sodium|openssl|curl|fileinfo|gd|intl|zip'
```

Skip the PPA steps if `apt` already provides `php8.4-*` on your image.

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

Prefer **MySQL 8.4** when packages allow (architecture baseline). Ubuntu images often ship **8.0**; if 8.4 is unavailable, record the version and accept untested compatibility risk.

### 4.1 On-instance MySQL (recommended for trial)

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

### 4.2 Optional: Linode Managed MySQL

If using Managed Database instead of local MySQL:

- [ ] Create MySQL cluster in the **same region**; prefer **VPC / private** connectivity  
- [ ] Allow this Linode’s private IP (or VPC) in the DB access list — **not** `0.0.0.0/0`  
- [ ] Create `academy_lms` + user with utf8mb4  
- [ ] Set `DB_HOST` to the managed hostname; keep `DB_CHARSET=utf8mb4`  
- [ ] Still **deny** public 3306 on the app Linode  

---

## 5. Composer

```bash
curl -sS https://getcomposer.org/installer | php8.4 -- --install-dir=/usr/local/bin --filename=composer
composer --version   # expect 2.x
```

- [ ] Composer 2.x on PATH for the deploy user  

### Node (build only)

Install Node **≥22** on the Linode **or** build assets elsewhere and deploy `public/assets/vendor`:

```bash
# On build machine or Linode
npm ci
node bin/install-frontend-assets.mjs
```

---

## 6. Application deployment on the Linode

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

## 7. SSL on Linode VPS

Linode does not terminate HTTPS for a plain Compute Instance by default — issue certificates on the instance (or terminate at a reverse proxy you control).

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

- Terminate TLS on a load balancer / reverse proxy in front of the Linode; set `TRUSTED_PROXIES` and forward `X-Forwarded-Proto`.  
- Still keep the app document root as `…/public` on the origin.

### After TLS

- [ ] `https://<domain>` loads with valid certificate  
- [ ] HTTP redirects to HTTPS  
- [ ] Set `APP_URL=https://<domain>`, `FORCE_HTTPS=true`, `SESSION_COOKIE_SECURE=true`  
- [ ] Reload PHP-FPM after env change  

---

## 8. Environment variables (Linode VPS)

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

**Trial posture reminder:** Prefer `APP_ENV=uat` with real Razorpay + real SMTP + explicit local documents + fake scanner. `APP_ENV=production` forbids local document storage and there is **no S3 adapter** in-repo (Linode Object Storage does not close that gap by itself).

- [ ] Env file created and secured  
- [ ] PHP-FPM and CLI/cron see the same values  
- [ ] `PAYMENTS_FAKE_GATEWAY=0`  

---

## 9. Cron workers on Linode VPS

Linode Compute Instances use normal Linux cron (or systemd timers — see [PROCESS_SUPERVISION_EXAMPLES.md](../operations/PROCESS_SUPERVISION_EXAMPLES.md)). There is no separate “panel cron” for this stack.

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
- [ ] Nightly `mysqldump` (+ checksum) scheduled; Linode Backup Service noted if enabled  

### Razorpay webhook (after HTTPS)

Dashboard URL: `https://<domain>/webhooks/razorpay`  
Events: at least `payment.captured`, `payment.failed` (recommended: `order.paid`, `payment.authorized`)  
Copy secret → `RAZORPAY_WEBHOOK_SECRET` → reload FPM.

Architecture: browser return is informational; `payment:webhook-process` drives success/admit.

### SMTP

Configure SES/SMTP vars; ensure `outbox:relay` + `notification:deliver` run every minute. Details: [EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md).

---

## 10. Branding on Linode

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
- [ ] Backup: Linode snapshot/Backup Service and/or `mysqldump` rehearsed once  

Full acceptance: [FIRST_CUSTOMER_TRIAL_RUNBOOK.md](./FIRST_CUSTOMER_TRIAL_RUNBOOK.md) §12.

### Sign-off

| Role | Name | Date | Pass? |
|---|---|---|---|
| Deployer | | | ☐ |
| Facilitator | | | ☐ |
| Product Owner | | | ☐ |

---

## 12. Linode-specific pitfalls

| Pitfall | Mitigation |
|---|---|
| Undersized Nanode (1 GB) | Use ≥ 2–4 GB for PHP-FPM + MySQL + Dompdf |
| Document root set to repo root | Must be `…/academy-lms/public` |
| Cloud Firewall opens **3306** | Keep MySQL localhost or private VPC only |
| Cron without env | Workers fail closed on missing peppers/keys — source `trial.env` |
| Skipping webhook cron | Payments stick on “Confirming payment…” |
| Using fake Razorpay on customer host | Keep `PAYMENTS_FAKE_GATEWAY=0` |
| Assuming Linode Object Storage = production docs | No S3 adapter in-repo; `production` + local storage is forbidden |
| `APP_ENV=production` + local documents | Validator forbids; no S3 driver in-repo |
| UAT seed on customer DB | Contaminates real PII — do not run |
| SSH lockout after firewall change | Use **LISH Console** to recover |

---

## Quick command index

| Step | Command / place |
|---|---|
| SSH | `ssh root@<IP>` or Cloud Manager → **LISH Console** |
| PHP | `php8.4-fpm` / `php8.4` |
| Migrate | `php8.4 vendor/bin/phinx migrate -c phinx.php` |
| SSL | `certbot --nginx -d <domain>` |
| Workers | `crontab -u academy -e` → `bin/jobs.php …` |
| Health | `curl https://<domain>/health/ready` |
| Firewall | Cloud Manager → **Firewalls** |

---

*Linode VPS adaptation of the Phase 1 single-customer guides. No application code changes.*
