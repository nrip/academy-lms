# First Customer Trial — Execution Runbook

**Authority:** [SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md)  
**Application commit to deploy:** `31363c5`  
**Purpose:** Step-by-step execution checklist for the first single-customer 5-day trial on Ubuntu VPS / AWS EC2 / Hostinger VPS.  
**Rule:** Tick items in order. Do not invent features. Do not run UAT/demo seeders against real customer PII.

| Field | Value |
|---|---|
| Customer / academy name | |
| Public domain | |
| Host (IP / instance) | |
| Deployer | |
| Start date (UTC) | |
| Chosen `APP_ENV` | ☐ `uat` (Mode A + local docs) · ☐ `production` (docs upload out of scope) |
| Razorpay mode | ☐ Test · ☐ Live |
| Risk acceptance recorded (local docs + fake scanner if `uat`) | ☐ |

---

## 0. Pre-flight decisions (complete before provisioning)

- [ ] Product/PO agrees trial posture: `APP_ENV=uat` + local documents + fake scanner **or** `production` without credential uploads  
- [ ] Customer branding assets received (name, logo file or HTTPS URL, primary colour, support email, certificate issuer)  
- [ ] Razorpay account ready (test keys for rehearsal; live keys only when taking real money)  
- [ ] SMTP/SES sender domain or address ready to verify  
- [ ] DNS zone access available  
- [ ] Deploy key / clone access to repository available  
- [ ] Written note: this is a **controlled trial**, not production cutover (`PR-S3` / `PR-MALWARE` still open)

---

## 1. Server provisioning steps

### 1.1 Create host

- [ ] Provision Ubuntu **22.04 or 24.04** LTS (2 vCPU / 4 GB RAM / ≥40 GB SSD starting point)  
- [ ] Attach public IP / Elastic IP / Hostinger IP as needed  
- [ ] Create non-root sudo user (e.g. `academy`)  
- [ ] SSH key-only login; disable password root login  
- [ ] Open firewall: **22** (SSH, restricted), **80**, **443**; **deny** public **3306**  
- [ ] Set hostname; confirm `timedatectl` (prefer UTC)

### 1.2 OS packages

```bash
sudo apt update
sudo apt install -y \
  nginx mysql-server git unzip curl ca-certificates \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath
```

- [ ] Packages installed (add ondrej/PPA or equivalent if distro lacks PHP 8.4)  
- [ ] `php8.4 -v` shows 8.4.x  
- [ ] Extensions present:

```bash
php8.4 -m | grep -E 'pdo_mysql|mbstring|json|sodium|openssl|curl|fileinfo|gd|intl|zip'
```

- [ ] Composer 2.x installed:

```bash
curl -sS https://getcomposer.org/installer | php8.4 -- --install-dir=/usr/local/bin --filename=composer
composer --version
```

- [ ] Node **≥22** available on this host **or** a build machine (assets only)

### 1.3 App user and directories

```bash
sudo mkdir -p /var/www/academy-lms /etc/academy-lms /var/log/academy-lms
sudo chown -R academy:academy /var/www/academy-lms /var/log/academy-lms
```

- [ ] Directories created and owned by deploy user  

---

## 2. DNS setup

- [ ] Create **A** (and **AAAA** if used) record for customer hostname → server public IP  
- [ ] Optional: `www` CNAME → apex (if customer wants www)  
- [ ] TTL set appropriately for cutover (low during setup, raise after stable)  
- [ ] Propagation verified:

```bash
dig +short <customer-domain>
curl -sI http://<customer-domain>/ | head -5
```

- [ ] Record canonical hostname for `APP_URL` (no path, `https://` once SSL is live)

---

## 3. SSL setup

Do this after Nginx is serving HTTP on the domain (or use Hostinger/ACM panel SSL).

- [ ] Issue certificate (Let’s Encrypt example: `certbot --nginx -d <domain>`) **or** install panel SSL  
- [ ] HTTPS responds with valid certificate  
- [ ] HTTP redirects to HTTPS  
- [ ] Confirm `APP_URL=https://<domain>` matches certificate SAN/CN  
- [ ] Plan: set `FORCE_HTTPS=true` and `SESSION_COOKIE_SECURE=true` in env (§6)

---

## 4. PHP / MySQL installation

### 4.1 PHP-FPM tuning

- [ ] Set in php.ini or pool: `upload_max_filesize=10M`, `post_max_size=16M`, `memory_limit=256M`  
- [ ] PHP-FPM pool runs as `academy` (or shared user consistent with file ownership)  
- [ ] `sudo systemctl enable --now php8.4-fpm`  
- [ ] Restart after env changes: `sudo systemctl restart php8.4-fpm`

### 4.2 MySQL

- [ ] MySQL 8.4 (prefer) running and enabled  
- [ ] Create database and user (utf8mb4):

```sql
CREATE DATABASE academy_lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'academy'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON academy_lms.* TO 'academy'@'localhost';
FLUSH PRIVILEGES;
```

- [ ] App can connect from localhost only (no public 3306)  
- [ ] Record DB credentials for env file (not in git)

### 4.3 Nginx

- [ ] Site `root` = `/var/www/academy-lms/public`  
- [ ] Front controller: `try_files $uri /index.php…` (see `public/nginx.conf.example`)  
- [ ] PHP → `php8.4-fpm` socket  
- [ ] Deny access to `src|config|database|tests|storage|vendor|templates|bin`  
- [ ] TLS server block active; proxy headers if behind LB  
- [ ] `sudo nginx -t && sudo systemctl reload nginx`

---

## 5. Application deployment commands

Execute as deploy user unless noted.

```bash
cd /var/www
git clone <REPO_URL> academy-lms
cd academy-lms
git fetch --prune
git checkout 31363c5
git rev-parse HEAD   # must equal 31363c5
```

- [ ] Code at `31363c5`  

```bash
composer install --no-dev --optimize-autoloader
```

- [ ] Composer install complete  

```bash
# On host or CI build machine with Node >= 22
npm ci
node bin/install-frontend-assets.mjs
```

- [ ] `public/assets/vendor` contains Bootstrap + jQuery  

```bash
mkdir -p storage/logs storage/documents storage/mail storage/cache storage/sessions storage/tmp
chmod -R u+rwX,g+rX storage
```

- [ ] `storage/` writable by PHP-FPM user  
- [ ] Env file prepared and loaded by FPM + CLI (§6) — **before** migrate/health checks  
- [ ] **Do not** run: `uat:seed`, `uat:reset`, `demo:prepare`, Phinx demo seeders on this DB  
- [ ] `ALLOW_LOCAL_BOOTSTRAP_ADMIN=false`

---

## 6. Environment variables checklist

Create `/etc/academy-lms/trial.env` (mode `0600`). Wire into PHP-FPM and cron/systemd.

### Application / security

| Variable | Set? | Value notes |
|---|---|---|
| `APP_ENV` | ☐ | `uat` (recommended trial) or `production` |
| `APP_URL` | ☐ | `https://<domain>` |
| `APP_DEBUG` | ☐ | `false` |
| `FORCE_HTTPS` | ☐ | `true` |
| `SESSION_COOKIE_SECURE` | ☐ | `true` |
| `TRUSTED_PROXIES` | ☐ | blank or LB IPs |
| `RATE_LIMIT_PEPPER` | ☐ | unique strong secret |
| `TOKEN_PEPPER` | ☐ | unique strong secret |
| `OTP_PEPPER` | ☐ | different from token pepper |
| `NOTIFICATION_DELIVERY_KEY` | ☐ | 32-byte key, strict base64 |
| `TERMS_VERSION` / `PRIVACY_VERSION` | ☐ | match published legal |
| `ALLOW_LOCAL_BOOTSTRAP_ADMIN` | ☐ | `false` |

### Database

| Variable | Set? |
|---|---|
| `DB_HOST` | ☐ |
| `DB_PORT` | ☐ |
| `DB_NAME` | ☐ |
| `DB_USER` | ☐ |
| `DB_PASSWORD` | ☐ |
| `DB_CHARSET` | ☐ `utf8mb4` |

### Branding

| Variable | Set? |
|---|---|
| `ACADEMY_NAME` | ☐ |
| `ACADEMY_LOGO_URL` | ☐ same-origin path or HTTPS URL |
| `ACADEMY_PRIMARY_COLOR` | ☐ `#RRGGBB` |
| `ACADEMY_SUPPORT_EMAIL` | ☐ |
| `ACADEMY_CERTIFICATE_ISSUER_NAME` | ☐ |

### Payments

| Variable | Set? |
|---|---|
| `RAZORPAY_KEY_ID` | ☐ |
| `RAZORPAY_KEY_SECRET` | ☐ |
| `RAZORPAY_WEBHOOK_SECRET` | ☐ (after Dashboard webhook created — may fill in §9) |
| `PAYMENTS_FAKE_GATEWAY` | ☐ `0` |

### Email

| Variable | Set? |
|---|---|
| `MAIL_DRIVER` | ☐ `smtp` or `ses` |
| `MAIL_HOST` | ☐ |
| `MAIL_PORT` | ☐ |
| `MAIL_ENCRYPTION` | ☐ |
| `MAIL_USERNAME` | ☐ |
| `MAIL_PASSWORD` | ☐ |
| `MAIL_FROM_ADDRESS` | ☐ |
| `MAIL_FROM_NAME` | ☐ |

### Storage / documents (trial `uat` posture)

| Variable | Set? | Notes |
|---|---|---|
| `DOCUMENTS_STORAGE_DRIVER` | ☐ | `local` only if `APP_ENV=uat` |
| `DOCUMENTS_LOCAL_BASE_PATH` | ☐ | e.g. `storage/documents` |
| `DOCUMENTS_LOCAL_SIGNING_SECRET` | ☐ | strong secret |
| `DOCUMENTS_FAKE_SCANNER` | ☐ | `1` only on `uat` trial; never on production |

### Logging / outbox

| Variable | Set? |
|---|---|
| `LOG_LEVEL` | ☐ `info` or `warning` |
| `LOG_PATH` | ☐ `storage/logs/app.log` |
| `OUTBOX_TRANSPORT` | ☐ configured for mail path |

- [ ] Env loaded by PHP-FPM (pool `clear_env` / `env[]` or `EnvironmentFile`)  
- [ ] Env loaded by cron/systemd workers (same file)  
- [ ] Secrets not committed to git  

---

## 7. Database migration steps

```bash
cd /var/www/academy-lms
# Ensure env is visible to this shell
php8.4 vendor/bin/phinx status -c phinx.php
php8.4 vendor/bin/phinx migrate -c phinx.php
php8.4 vendor/bin/phinx status -c phinx.php
```

- [ ] Empty DB created before first migrate  
- [ ] Migrate completed with exit 0  
- [ ] Status shows all migrations up, including `20260906000001_wp_l10_video_content_items`  
- [ ] Optional: `php8.4 bin/setup.php` only on empty/new DB — **without** `--seed-uat`  
- [ ] No demo/UAT seed run against customer data  

Create privileged users via approved procedure for this env (Course Admin / Super Admin as required).

- [ ] Admin account exists and can sign in  
- [ ] Course Admin account exists (or Super Admin will author course)

---

## 8. Worker setup

Prefer `/etc/cron.d/academy-lms` or systemd timers ([PROCESS_SUPERVISION_EXAMPLES.md](../operations/PROCESS_SUPERVISION_EXAMPLES.md)).

Example cron (customize paths; load env in a wrapper if needed):

```cron
MAILTO=""
*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php payment:webhook-process >> /var/log/academy-lms/webhook.log 2>&1
*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php outbox:relay >> /var/log/academy-lms/outbox.log 2>&1
*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php notification:deliver >> /var/log/academy-lms/notification.log 2>&1
*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php document:scan >> /var/log/academy-lms/document-scan.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php payment:reconcile >> /var/log/academy-lms/reconcile.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php document:stuck-scan >> /var/log/academy-lms/stuck-scan.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php session:cleanup >> /var/log/academy-lms/session-cleanup.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php rate-limit:cleanup >> /var/log/academy-lms/rate-limit-cleanup.log 2>&1
0 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php token-confirmation:cleanup >> /var/log/academy-lms/token-cleanup.log 2>&1
```

- [ ] Cron/systemd installed and enabled  
- [ ] Workers inherit same secrets as PHP-FPM  
- [ ] Manual smoke: `php8.4 bin/jobs.php payment:webhook-process` exits 0  
- [ ] Logs appear under `/var/log/academy-lms/`  
- [ ] **Not** scheduled: `uat:seed`, `uat:reset`, `demo:process`  
- [ ] Nightly DB backup job scheduled (mysqldump + checksum); restore plan noted  

---

## 9. Razorpay webhook setup

- [ ] `PAYMENTS_FAKE_GATEWAY=0`  
- [ ] Test or live `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET` set  
- [ ] In Razorpay Dashboard → Webhooks → create:  
  - URL: `https://<domain>/webhooks/razorpay`  
  - Events: `payment.captured`, `payment.failed` (+ recommended `order.paid`, `payment.authorized`)  
- [ ] Copy webhook secret → `RAZORPAY_WEBHOOK_SECRET`  
- [ ] Reload PHP-FPM after secret change  
- [ ] Confirm `payment:webhook-process` cron is running  
- [ ] Architecture check understood: browser return is informational; worker drives success/admit  

Details: [RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md).

---

## 10. SMTP setup

- [ ] Choose provider (Amazon SES SMTP or other SMTP)  
- [ ] Verify domain or `MAIL_FROM_ADDRESS`  
- [ ] Leave SES sandbox **or** verify all trial recipient addresses  
- [ ] Set `MAIL_DRIVER=smtp` or `ses` plus host/port/user/pass/from  
- [ ] Confirm `outbox:relay` + `notification:deliver` scheduled  
- [ ] Send a real test (register or password reset to a controlled inbox)  
- [ ] Confirm message leaves outbox (not stuck; not `local_file`)  

Details: [EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md).

---

## 11. Customer branding setup

- [ ] Set `ACADEMY_NAME`  
- [ ] Deploy logo: same-origin file under `public/assets/brand/…` **or** HTTPS URL  
- [ ] Set `ACADEMY_LOGO_URL` accordingly (HTTPS logo host must match CSP allow-list built from this URL)  
- [ ] Set `ACADEMY_PRIMARY_COLOR`  
- [ ] Set `ACADEMY_SUPPORT_EMAIL` (monitored mailbox)  
- [ ] Set `ACADEMY_CERTIFICATE_ISSUER_NAME`  
- [ ] Restart/reload PHP-FPM  
- [ ] Visual check: login page + header show customer name/logo/colour  
- [ ] Certificate issuer name correct on a sample certificate after learning path  

---

## 12. Final acceptance test

Run after §§1–11. Do **not** invite the customer until this section passes.

### 12.1 Platform health

```bash
curl -sS https://<domain>/health/live
curl -sS https://<domain>/health/ready
```

- [ ] Domain works over HTTPS  
- [ ] SSL valid  
- [ ] `/health/live` → 200  
- [ ] `/health/ready` → 200  
- [ ] Commit on server is `31363c5`  

### 12.2 Branding & access

- [ ] Academy branding configured and visible  
- [ ] Admin can sign in  
- [ ] Course Admin can open `/admin/courses`  

### 12.3 Course & learning

- [ ] Course created/published; open batch with `starts_at` in the past  
- [ ] Video lesson added and plays (embed or Watch Video)  
- [ ] Learner Active enrolment reaches course player  
- [ ] Mark complete works on video/text  
- [ ] Assessment pass shows completion messaging + certificate CTA  
- [ ] Certificate HTML/PDF opens; public verify URL works logged out  

### 12.4 Payments

- [ ] Payment checkout (test or live) completes  
- [ ] Result shows confirming then success after webhook processing  
- [ ] Application **Admitted** and Enrolment created  
- [ ] Enrolment lifecycle **Active** (batch already started)  

### 12.5 Email

- [ ] At least one real email received (identity or transactional)  

### 12.6 Documents (if in trial scope)

- [ ] Upload → `document:scan` → reviewer path works under agreed `uat`/local/fake posture  
- [ ] Finance user cannot open document URLs  

### 12.7 Ops

- [ ] Backup job ran once successfully  
- [ ] Worker logs show recent healthy runs  
- [ ] Facilitator briefed on known limitations (no S3, no MFA UI, no video hosting, PDF lesson placeholder, etc.)  

### Sign-off — ready for customer access

| Role | Name | Date | Pass? |
|---|---|---|---|
| Deployer | | | ☐ |
| Facilitator | | | ☐ |
| Product Owner | | | ☐ |

**Customer access granted:** ☐ No · ☐ Yes — date/time UTC: ________  

---

## Quick reference — forbidden on this host

| Action | Why |
|---|---|
| `PAYMENTS_FAKE_GATEWAY=1` | Not for customer money path |
| `uat:seed` / demo seeders on real PII DB | Contaminates trial data |
| `APP_ENV=production` + `DOCUMENTS_STORAGE_DRIVER=local` | Validator forbids; no S3 adapter |
| Skipping `payment:webhook-process` | Admissions will stall on Confirming… |
| Skipping mail workers | Outbox will not deliver |

---

*Execution checklist only. Companion guide: [SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md). No application code changes.*
