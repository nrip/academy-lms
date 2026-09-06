# Single-Customer Deployment Guide — Phase 1 Release Candidate

**RC HEAD:** `31363c5` (`fix(lms): clear Phase 1 trial blockers for payment, seed, and UX`)  
**Branch (typical):** `demo/mode-a-user-demo` (or the approved RC tag cut from this SHA)  
**Audience:** Ops / engineer deploying **one** Academy LMS instance for a single customer trial on Ubuntu VPS, AWS EC2, or Hostinger VPS  
**Nature:** Application deployment checklist. Host provisioning details vary by provider; adapt paths/users accordingly.

**Related docs:**  
[RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md) · [EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md) · [WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md) · [PROCESS_SUPERVISION_EXAMPLES.md](../operations/PROCESS_SUPERVISION_EXAMPLES.md) · [BACKUP_RESTORE_RUNBOOK.md](../operations/BACKUP_RESTORE_RUNBOOK.md) · [PHASE1_RELEASE_CHECKLIST.md](../release/PHASE1_RELEASE_CHECKLIST.md)

---

## Scope and posture

| Item | Guidance |
|---|---|
| Single deployment | One academy branding + one Razorpay account + one SMTP sender per environment |
| `APP_ENV` for customer trial | Prefer `production` (or `staging` for dry-run). Do **not** use `local` / `uat` on a public customer host |
| Fake adapters | **Forbidden** in `staging` / `production` (`PAYMENTS_FAKE_GATEWAY`, local document storage as sole strategy, `local_file` / `recording` email) |
| Demo / UAT seed | **Never** run `uat:seed`, `uat:reset`, or `demo:prepare` on the customer production database |
| Dotenv | `.env` file load is for `local` / `testing` / `ci` / `uat` only. Staging/production should inject env via the process manager / systemd `EnvironmentFile` / panel secrets — do not rely on committed soft defaults |

This guide targets a **facilitated 5-day Phase 1 trial**, not a claim of full production DR / multi-tenant readiness.

---

## 1. Server requirements

### Operating system

| Requirement | Value |
|---|---|
| OS | **Ubuntu 22.04 LTS or 24.04 LTS** (recommended). Equivalent Amazon Linux / Debian OK if packages match |
| Architecture | `x86_64` or `arm64` with PHP 8.4 packages available |
| Access | SSH with a non-root deploy user; `sudo` for packages and services |

### PHP

| Requirement | Value |
|---|---|
| Version | **PHP 8.4** (`^8.4` in `composer.json`) — FPM + CLI same major.minor |
| Upload limits | `upload_max_filesize=10M`, `post_max_size=16M` (credential documents capped at 10 MB) |
| Memory | At least `128M` PHP memory; prefer `256M` for Dompdf certificate generation |
| Timezone | Prefer UTC in PHP; app stores timestamps in UTC |

### Required PHP extensions

Confirmed by CI / Composer / runtime usage:

| Extension | Why |
|---|---|
| `pdo_mysql` | Database |
| `mbstring` | Strings / validation |
| `json` | API / JSON columns |
| `sodium` | Cryptography (`ext-sodium` required by Composer) |
| `openssl` | HTTPS / SMTP TLS / signed URLs |
| `curl` | Outbound HTTP (Razorpay, SMTP providers as applicable) |
| `fileinfo` | Upload MIME checks |
| `gd` or `imagick` | Dompdf image/PDF rendering (install `gd` at minimum) |
| `intl` | Recommended for Dompdf / locale-safe formatting |
| `zip` | Composer packages / Dompdf assets |

Verify:

```bash
php8.4 -v
php8.4 -m | grep -E 'pdo_mysql|mbstring|json|sodium|openssl|curl|fileinfo|gd|intl|zip'
```

### MySQL

| Requirement | Value |
|---|---|
| Version | **MySQL 8.4 LTS** (InnoDB, `utf8mb4`) — project baseline |
| Charset / collation | `utf8mb4` / `utf8mb4_unicode_ci` (or server default compatible with utf8mb4) |
| Privileges | App user: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `INDEX`, `REFERENCES` on the app schema (migrations need DDL) |
| Client tools | `mysql`, `mysqldump` for backup/restore |

### Composer

| Requirement | Value |
|---|---|
| Composer | **2.x** matching PHP 8.4 |
| Install mode (customer host) | `composer install --no-dev --optimize-autoloader` |

### Node.js (build-time only)

Frontend is Bootstrap 5.3 + jQuery copied into `public/assets/vendor`. Node is **not** required at runtime.

| Requirement | Value |
|---|---|
| Node | **≥ 22** (`package.json` `engines`) |
| When | Deploy/build machine: `npm ci` then `node bin/install-frontend-assets.mjs` (or `composer assets:install` / `npm run assets:install`) |
| Runtime | Serve static files from `public/`; no Node process needed |

### Suggested host sizing (trial)

| Resource | Starting point |
|---|---|
| vCPU | 2 |
| RAM | 4 GB |
| Disk | 40 GB SSD (OS + app + MySQL + logs + local document storage if used) |
| Network | Public HTTPS; outbound HTTPS for Razorpay + SMTP |

---

## 2. Application deployment

### 2.1 Clone repository

```bash
sudo mkdir -p /var/www
sudo chown "$USER":"$USER" /var/www
cd /var/www
git clone <REPO_URL> academy-lms
cd academy-lms
git fetch --prune
git checkout 31363c5   # or approved RC tag
git rev-parse HEAD     # must equal 31363c5 (or tag tip)
```

Use a deploy key or CI artifact; do not leave personal tokens on the VPS.

### 2.2 Environment setup

1. Create MySQL database and user (utf8mb4).
2. Provide **all** required environment variables to PHP-FPM and CLI (see §4).  
   - Preferred: `/etc/academy-lms/production.env` (mode `0600`, owned by root, readable by app user) referenced by systemd / PHP-FPM `clear_env` + `env[]` / `EnvironmentFile`.  
   - If the host must use a project `.env`, understand dotenv soft-load is designed for non-production-like envs — for `APP_ENV=production`, inject via the process environment.
3. Set `APP_ENV=production` (or `staging` for a private dry-run).
4. Set `APP_DEBUG=false`, `FORCE_HTTPS=true` behind TLS.
5. Set `APP_URL=https://<customer-domain>`.

### 2.3 Composer install

```bash
cd /var/www/academy-lms
composer install --no-dev --optimize-autoloader
```

Record `composer.lock` hash in the deploy note.

### 2.4 Frontend assets

On a machine with Node 22+:

```bash
npm ci
node bin/install-frontend-assets.mjs
# verifies files under public/assets/vendor/{bootstrap,jquery}
```

Commit/deploy the generated vendor assets with the release, or run this on the VPS if Node is installed there.

### 2.5 Migrations

```bash
php8.4 vendor/bin/phinx migrate -c phinx.php
# or: composer migrate   # when vendor/bin is available
```

- Run **forward** migrations only on customer data.
- Confirm WP-L10 video migration is included on this HEAD (`20260906000001_wp_l10_video_content_items`).
- Prefer fix-forward if a migration fails mid-way; do not invent destructive rollbacks on a live trial DB.

Optional clean-install check (empty DB only):

```bash
php8.4 bin/setup.php
```

Do **not** use `php bin/setup.php --seed-uat` on the customer trial database.

### 2.6 Seed / demo removal (critical)

| Action | Customer trial host |
|---|---|
| `php bin/jobs.php uat:seed` | **Do not run** (refused in staging/production; still never point at customer DB from a mis-set env) |
| `php bin/jobs.php uat:reset --confirm` | **Do not run** |
| `composer demo-prepare` / `demo:process` | **Do not run** |
| `Wp02DemoCatalogueSeeder` / Phase 1 learning seeder | **Do not run** via Phinx seed on production |
| `ALLOW_LOCAL_BOOTSTRAP_ADMIN` | Must be `false` |
| `PAYMENTS_FAKE_GATEWAY` | Must be unset / `0` |
| `DOCUMENTS_FAKE_SCANNER` | Must be unset / off |
| `DOCUMENTS_STORAGE_DRIVER=local` | Allowed only as an explicit short-term trial choice with documented risk; prefer private object storage for anything beyond a private dry-run |
| `NOTIFICATION_EMAIL_ADAPTER=local_file\|recording` | **Forbidden** in staging/production |

Create the customer Super Admin / Course Admin accounts through the approved bootstrap path for production (or a controlled first-user procedure). Do not ship UAT passwords (`Uat-Demo-Passw0rd!`) to the customer host.

If a staging dry-run used UAT seed, **wipe and re-migrate** before customer data entry — do not “clean up” demo rows ad hoc.

### 2.7 Web document root

Point the web server **only** at:

```text
/var/www/academy-lms/public
```

Deny direct HTTP access to `src/`, `config/`, `database/`, `storage/`, `tests/`, `vendor/`, `.env*`.

All requests enter via `public/index.php` (front controller).

---

## 3. Runtime services

### 3.1 PHP-FPM

- Pool for site user (e.g. `academy`).
- Same env vars as CLI workers.
- `php.ini` / pool overrides: `upload_max_filesize=10M`, `post_max_size=16M`.
- Restart after env changes: `sudo systemctl restart php8.4-fpm`.

### 3.2 Nginx or Apache

**Nginx (recommended sketch):**

- `root /var/www/academy-lms/public;`
- `try_files $uri /index.php?$query_string;`
- Pass PHP to `unix:/run/php/php8.4-fpm.sock` (or TCP).
- TLS termination here or at a load balancer / Hostinger SSL.
- Forward `X-Forwarded-Proto` / `X-Forwarded-For` when behind a proxy; set `TRUSTED_PROXIES` accordingly.
- Deny access to hidden files and non-public trees.

**Apache:** `DocumentRoot` → `public/`; `FallbackResource /index.php` or equivalent rewrite; same TLS / proxy rules.

### 3.3 Cron / scheduled workers

Jobs are **batch** commands (`php bin/jobs.php <command>`), not long-lived daemons. Prefer **systemd timers** or **cron** (≥ 1 minute). See [PROCESS_SUPERVISION_EXAMPLES.md](../operations/PROCESS_SUPERVISION_EXAMPLES.md).

| Cadence | Commands |
|---|---|
| Every minute | `outbox:relay`, `notification:deliver`, `document:scan`, `payment:webhook-process` |
| Every 5 minutes | `session:cleanup`, `rate-limit:cleanup`, `document:stuck-scan`, `payment:reconcile` |
| Hourly | `token-confirmation:cleanup` |

Example (customize user/path/PHP):

```cron
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

**Never** schedule `uat:seed`, `uat:reset`, or `demo:process`.

### 3.4 Queue / outbox

- Application outbox is relayed by `outbox:relay`.
- Configure `OUTBOX_TRANSPORT` appropriately for the environment (must not remain silently unconfigured if notifications must leave the box).
- Payment confirmation for **real Razorpay** is: Dashboard webhook → `POST /webhooks/razorpay` → durable event → **`payment:webhook-process`** → existing payment/admission state machines. Do not rely on browser return.

### 3.5 Health checks

```bash
curl -sS https://<host>/health/live
curl -sS https://<host>/health/ready
```

Expect HTTP 200 when DB, writable paths, and adapter policy are healthy.

---

## 4. Environment variables

Use `.env.example` as the field catalogue. Values below are the **customer-trial** subset.

### 4.1 Application / security

| Variable | Trial requirement |
|---|---|
| `APP_ENV` | `production` (or `staging`) |
| `APP_DEBUG` | `false` |
| `APP_URL` | Public HTTPS origin |
| `APP_TIMEZONE` | `UTC` |
| `FORCE_HTTPS` | `true` |
| `TRUSTED_PROXIES` | Proxy/LB IPs if applicable |
| `SESSION_COOKIE_SECURE` | `true` on HTTPS |
| `RATE_LIMIT_PEPPER` | Strong unique secret |
| `TOKEN_PEPPER` | Strong unique secret |
| `OTP_PEPPER` | Strong unique secret (≠ token pepper) |
| `NOTIFICATION_DELIVERY_KEY` | 32-byte key, strict base64 |
| `TERMS_VERSION` / `PRIVACY_VERSION` | Match published legal versions |

### 4.2 Database

| Variable | Trial requirement |
|---|---|
| `DB_HOST` / `DB_PORT` | MySQL host |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | Dedicated schema credentials |
| `DB_CHARSET` | `utf8mb4` |

### 4.3 Branding

| Variable | Trial requirement |
|---|---|
| `ACADEMY_NAME` | Customer academy display name |
| `ACADEMY_LOGO_URL` | Same-origin path (e.g. `/assets/brand/logo.svg`) **or** HTTPS URL whose host is allowed by CSP |
| `ACADEMY_PRIMARY_COLOR` | `#RRGGBB` |
| `ACADEMY_SUPPORT_EMAIL` | Support contact |
| `ACADEMY_CERTIFICATE_ISSUER_NAME` | Certificate issuer line |

### 4.4 Razorpay

| Variable | Trial requirement |
|---|---|
| `RAZORPAY_KEY_ID` | Live or test key (test OK for private rehearsal; live for paid trial) |
| `RAZORPAY_KEY_SECRET` | Server only |
| `RAZORPAY_WEBHOOK_SECRET` | Dashboard webhook secret |
| `PAYMENTS_FAKE_GATEWAY` | **Off** (`0` / empty) |

Webhook URL: `https://<host>/webhooks/razorpay`  
Subscribe at least: `payment.captured`, `payment.failed` (plus recommended `order.paid` / `payment.authorized`).  
Details: [RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md).

### 4.5 Email

| Variable | Trial requirement |
|---|---|
| `MAIL_DRIVER` | `smtp` or `ses` |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_ENCRYPTION` | Provider endpoint |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | SMTP auth |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | Verified sender |
| `NOTIFICATION_EMAIL_ADAPTER` | Not `local_file` / `recording` |

Workers: `outbox:relay` + `notification:deliver` must be scheduled or mail will not leave the outbox.  
Details: [EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md).

### 4.6 Documents / storage

| Variable | Trial requirement |
|---|---|
| `DOCUMENTS_STORAGE_DRIVER` | Prefer private object storage for real PII; `local` only with explicit risk acceptance |
| `DOCUMENTS_LOCAL_BASE_PATH` | If local: under `storage/` outside web root |
| `DOCUMENTS_LOCAL_SIGNING_SECRET` | Strong secret |
| `DOCUMENTS_FAKE_SCANNER` | **Off** for customer-facing hosts (or accept malware-scan gap in writing) |
| Upload PHP limits | 10M / 16M as above |

### 4.7 Logging

| Variable | Trial requirement |
|---|---|
| `LOG_LEVEL` | `info` or `warning` (not `debug` on public hosts) |
| `LOG_PATH` | `storage/logs/app.log` (writable; rotated) |

---

## 5. Storage

### 5.1 Writable directories

Ensure the app user can write:

| Path | Purpose |
|---|---|
| `storage/` | Runtime storage root (readiness checks) |
| `storage/logs/` | Application logs |
| `storage/documents/` | Local document objects (if local driver) |
| `storage/mail/` | Only if local_file mail (not for production) |

```bash
sudo mkdir -p storage/logs storage/documents
sudo chown -R academy:academy storage
sudo chmod -R u+rwX,g+rX storage
```

Never expose `storage/` via the web server.

### 5.2 Backups

Minimum for a 5-day trial:

1. Nightly `mysqldump` (gzip + checksum) to disk **outside** the web root or to object storage.  
2. Copy `storage/documents` if using local driver.  
3. Record restore steps; rehearse once before day 1 if possible.

UAT-oriented scripts/docs: [BACKUP_RESTORE_RUNBOOK.md](../operations/BACKUP_RESTORE_RUNBOOK.md), `bin/backup-uat.sh`. Adapt for production credentials and retention. This does **not** by itself satisfy full production DR (`PR-BACKUP`).

---

## 6. SSL / domain setup

1. Point customer DNS `A`/`AAAA` (or CNAME) to the VPS / load balancer.
2. Issue certificate (Let’s Encrypt / Hostinger SSL / ACM).
3. Force HTTPS redirects; HSTS via app (`FORCE_HTTPS`) and/or edge.
4. Set `APP_URL` to the canonical `https://` origin (no trailing path).
5. Set `SESSION_COOKIE_SECURE=true`.
6. Configure `TRUSTED_PROXIES` if TLS terminates upstream.
7. Confirm Razorpay webhook HTTPS URL matches the public host.
8. Confirm outbound SMTP/Razorpay from the VPS is not blocked by security groups / firewall.

Smoke:

```bash
curl -sI https://<domain>/login
curl -sS https://<domain>/health/ready
```

---

## 7. First customer trial checklist

Execute in order; tick before inviting learners.

### A. Deploy freeze

| # | Check | Pass? |
|---|---|---|
| A1 | Deployed commit is `31363c5` (or approved tag of that SHA) | ☐ |
| A2 | `APP_ENV` is `production` or `staging` (not `local`/`uat`) | ☐ |
| A3 | `APP_DEBUG=false` | ☐ |
| A4 | No UAT/demo seed commands run against this DB | ☐ |
| A5 | Fake payment / fake scanner / local_file email disabled | ☐ |
| A6 | `composer install --no-dev` completed | ☐ |
| A7 | Migrations applied (incl. video content columns) | ☐ |
| A8 | Frontend vendor assets present under `public/assets/vendor` | ☐ |

### B. Platform health

| # | Check | Pass? |
|---|---|---|
| B1 | `GET /health/live` → 200 | ☐ |
| B2 | `GET /health/ready` → 200 | ☐ |
| B3 | Cron/systemd timers running; logs show recent success | ☐ |
| B4 | TLS valid; HTTP redirects to HTTPS | ☐ |
| B5 | Branding name/logo/color visible on login + header | ☐ |

### C. Money path (real Razorpay)

| # | Check | Pass? |
|---|---|---|
| C1 | Razorpay keys + webhook secret set | ☐ |
| C2 | Webhook URL reachable: `POST /webhooks/razorpay` | ☐ |
| C3 | Test payment → “Confirming…” → worker processes → Admitted + Enrolment | ☐ |
| C4 | Browser return alone does **not** mark success (architecture check) | ☐ |
| C5 | `payment:webhook-process` and `payment:reconcile` scheduled | ☐ |

### D. Mail path

| # | Check | Pass? |
|---|---|---|
| D1 | SMTP/`MAIL_DRIVER` configured; from-address verified | ☐ |
| D2 | `outbox:relay` + `notification:deliver` scheduled | ☐ |
| D3 | Registration / password / certificate email observed (or recorded as deferred) | ☐ |

### E. Learning path (Phase 1)

| # | Check | Pass? |
|---|---|---|
| E1 | Course Admin can create Video (YouTube embed) + text + MCQ | ☐ |
| E2 | Published batch with `starts_at` in the past → Active enrolment after admit | ☐ |
| E3 | Learner sees embedded video; Mark complete works | ☐ |
| E4 | MCQ pass shows completion message + certificate CTA | ☐ |
| E5 | Certificate PDF + public verify URL work logged out | ☐ |

### F. Documents / SoD

| # | Check | Pass? |
|---|---|---|
| F1 | Document upload + scan worker path works (or documented gap if scanner not ready) | ☐ |
| F2 | Finance cannot open credential document URLs | ☐ |

### G. Ops readiness for trial week

| # | Check | Pass? |
|---|---|---|
| G1 | Nightly DB backup job configured | ☐ |
| G2 | Log rotation configured for `storage/logs` and worker logs | ☐ |
| G3 | Support contact (`ACADEMY_SUPPORT_EMAIL`) monitored | ☐ |
| G4 | Rollback plan: previous release artifact + DB restore point named | ☐ |
| G5 | Facilitator knows remaining product gaps (PDF lesson download, MFA UI, etc.) | ☐ |

### Sign-off

| Role | Name | Date | Ack |
|---|---|---|---|
| Engineering / Deployer | | | ☐ |
| Customer facilitator | | | ☐ |
| Product Owner | | | ☐ |

---

## 8. Explicit non-goals for this guide

- Multi-tenant / white-label automation  
- Mux / DRM / hosted video pipeline (Phase 1 uses external URL / embed only)  
- Full production DR certification (`PR-BACKUP`, load, pen-test)  
- Running demo personas on the customer database  

---

*Document only — no application code changes. Keep this file aligned with HEAD `31363c5` until the next RC bump.*
