# Single-Customer Deployment Guide — LMS Phase 1 Release Candidate

**Feature RC HEAD:** `31363c5` (`fix(lms): clear Phase 1 trial blockers for payment, seed, and UX`)  
**Doc revision branch tip:** may sit on later docs-only commits; deploy **application code** at `31363c5` (or an approved tag of that SHA).  
**Audience:** Ops / engineer preparing a **single-customer 5-day trial** on Ubuntu VPS, AWS EC2, or Hostinger VPS.  
**Rules for this document:** Describes the repository as it is. Does not invent S3, MFA, or other unimplemented packs.

**Related (authoritative companions):**  
[RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md) · [EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md) · [WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md) · [PROCESS_SUPERVISION_EXAMPLES.md](../operations/PROCESS_SUPERVISION_EXAMPLES.md) · [BACKUP_RESTORE_RUNBOOK.md](../operations/BACKUP_RESTORE_RUNBOOK.md) · [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) · [PHASE1_RELEASE_CHECKLIST.md](../release/PHASE1_RELEASE_CHECKLIST.md) · `public/nginx.conf.example` · `.env.example`

---

## 1. Deployment target assumptions

### 1.1 Recommended minimum — first customer trial

| Layer | Assumption |
|---|---|
| OS | **Ubuntu 22.04 LTS or 24.04 LTS** |
| PHP | **8.4** FPM + CLI (`composer.json`: `php ^8.4`) |
| PHP extensions | `pdo_mysql`, `mbstring`, `json`, `sodium`, `openssl`, `curl`, `fileinfo`, `gd` (Dompdf), `intl` (recommended), `zip` (recommended). CI installs: `pdo_mysql`, `mbstring`, `json`, `sodium` |
| Database | **MySQL 8.4 LTS**, InnoDB, `utf8mb4` (architecture baseline). MariaDB is **not** the documented target — use MySQL 8.4 unless you accept untested compatibility risk |
| Composer | **2.x** |
| Web server | **Nginx** (example in `public/nginx.conf.example`) or Apache with document root = `public/` |
| SSL | **HTTPS required** for customer trial (`FORCE_HTTPS=true`, secure session cookies, Razorpay webhook) |
| Node | **≥ 22** at **build** time only (`package.json` engines) — not a runtime service |
| Host size (starting) | 2 vCPU, 4 GB RAM, 40 GB SSD |

### 1.2 Future production scale (not claimed complete in-repo)

| Layer | Direction (register / architecture) |
|---|---|
| Hosting | Approved AWS (or equivalent) layout — `PR-HOST` still open |
| Object storage | Private **S3** + IAM — `PR-S3` open; **no S3 adapter in `ObjectStorageFactory` today** |
| Malware | Real scanner pack — `PR-MALWARE` open; only `FakeMalwareScanner` / `UnconfiguredMalwareScanner` exist |
| Workers | Supervised systemd timers / managed scheduler — `PR-CRON` |
| Observability | Alerting integration — `PR-ALERT` |
| DR | Encrypted backups, restore RTO/RPO — `PR-BACKUP` |
| Security | Pen-test / MFA for privileged roles — `PR-SEC`, AGENTS MFA rule |

### 1.3 Critical `APP_ENV` posture for trial vs production-like

From `EnvironmentCapability` / `EnvironmentValidator` / `ObjectStorageFactory`:

| `APP_ENV` | Fake/local adapters | Customer-facing meaning |
|---|---|---|
| `local` / `testing` / `ci` / `uat` | Allowed with **explicit** flags | Can use `DOCUMENTS_STORAGE_DRIVER=local`, `DOCUMENTS_FAKE_SCANNER`, optional fake Razorpay |
| `staging` / `production` | **Fail closed** | Local document storage **forbidden**; fake scanner **forbidden**; fake Razorpay **forbidden**; Razorpay secrets **required**; SMTP/`MAIL_DRIVER` required for real email |

**Repository fact:** Production-like environments have **no implemented private object-storage driver** (factory returns `UnconfiguredObjectStorage` unless driver is `local` **and** env allows fake/local). Likewise there is **no production malware scanner implementation**.

Therefore the honest **first 5-day trial** recommendation is:

- Prefer `APP_ENV=uat` on the VPS for a Mode A + documents trial, with **real** Razorpay + **real** SMTP, and **explicit** local documents + fake scanner flags — treat as a **controlled trial host**, not production cutover.  
- Or `APP_ENV=production` only if you accept that **credential document upload/scan is blocked** until S3 + scanner packs land (learning/certs still work for already-admitted enrolments).

Do **not** run `uat:seed` / demo catalogue seeders against a database that will hold real customer PII without a wipe plan.

---

## 2. Server setup

### 2.1 OS packages (Ubuntu example)

Adapt package names to the Ubuntu release; ensure PHP **8.4** packages are available (ondrej PPA or distro equivalent).

```bash
sudo apt update
sudo apt install -y \
  nginx \
  mysql-server \
  git unzip curl ca-certificates \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath
# Confirm sodium is present (often bundled):
php8.4 -m | grep -i sodium
```

### 2.2 PHP installation / FPM

| Setting | Value |
|---|---|
| Version | 8.4 FPM + CLI identical |
| `upload_max_filesize` | `10M` |
| `post_max_size` | `16M` |
| `memory_limit` | Prefer `256M` (Dompdf certificates) |
| Pool user | Dedicated deploy user (e.g. `academy`) |

Restart after changes: `sudo systemctl restart php8.4-fpm`.

### 2.3 Composer

```bash
curl -sS https://getcomposer.org/installer | php8.4 -- --install-dir=/usr/local/bin --filename=composer
composer --version   # 2.x
```

### 2.4 MySQL setup

1. Install MySQL 8.4 (or 8.0+ only if 8.4 packages unavailable — prefer 8.4).  
2. Create database + user with utf8mb4.  
3. Grant DDL + DML for migrations.  
4. Prefer binding to localhost or private network; do not expose 3306 publicly.

### 2.5 Nginx / Apache

**Document root must be `…/public` only.** Example: `public/nginx.conf.example`.

Requirements:

- `try_files $uri /index.php…` (or Apache equivalent front-controller rewrite)
- PHP passed to PHP 8.4-FPM
- Deny HTTP access to `src`, `config`, `database`, `tests`, `storage`, `vendor`, `templates`, `bin`
- TLS server block (or terminate TLS at LB / Hostinger panel) and redirect HTTP→HTTPS
- Forward `X-Forwarded-Proto` / `X-Forwarded-For` when behind a proxy; set `TRUSTED_PROXIES`

### 2.6 File permissions

```bash
sudo mkdir -p /var/www/academy-lms
sudo chown -R academy:academy /var/www/academy-lms
cd /var/www/academy-lms
mkdir -p storage/logs storage/documents storage/mail storage/cache storage/sessions storage/tmp
chmod -R u+rwX,g+rX storage
# Web server must not serve storage/; only PHP writes there
```

---

## 3. Application deployment

Exact application steps (no invented caches/frameworks — this app has no Laravel-style `config:cache`).

### 3.1 Obtain code

```bash
cd /var/www
git clone <REPO_URL> academy-lms
cd academy-lms
git fetch --prune
git checkout 31363c5
git rev-parse HEAD   # must print 31363c5
```

### 3.2 Configure environment

Inject env into **both** PHP-FPM and CLI (cron/systemd). Prefer systemd `EnvironmentFile=/etc/academy-lms/trial.env` (mode `0600`).

Notes from `config/app.php`:

- Dotenv file load is intended for `local|testing|ci|uat`.  
- For `staging|production`, do not depend on soft secret defaults; inject real secrets via process environment.

### 3.3 Composer install

```bash
composer install --no-dev --optimize-autoloader
```

### 3.4 Frontend assets (build)

```bash
# Node >= 22
npm ci
node bin/install-frontend-assets.mjs
# copies Bootstrap/jQuery into public/assets/vendor
```

### 3.5 Database creation + migrations

```bash
# Create empty DB first (MySQL client)
php8.4 vendor/bin/phinx migrate -c phinx.php
```

Latest Phase 1 migration on this lineage includes video content items:  
`database/migrations/20260906000001_wp_l10_video_content_items.php`.

Optional empty-DB bootstrap check: `php8.4 bin/setup.php` — **do not** pass `--seed-uat` on a customer-bound database.

### 3.6 Seed requirements

| Command / seeder | Customer trial DB |
|---|---|
| `php bin/jobs.php uat:seed` | **Do not run** for real customer PII; refused in staging/production |
| `uat:reset --confirm` | **Do not run** on customer data |
| `demo:prepare` / Phinx demo seeders | **Do not run** on customer production data |
| Course content | Create via Course Admin UI (or a controlled import process you own) |

Create admin/course-admin users through an approved bootstrap procedure for the chosen `APP_ENV` (local bootstrap flags must stay **off** on public hosts: `ALLOW_LOCAL_BOOTSTRAP_ADMIN=false`).

### 3.7 Cache / config

No separate config-cache command exists. Opcache (PHP) is recommended at the host level. Application “cache” under `storage/cache` is runtime scratch — keep writable; no mandatory warm-up step in-repo.

---

## 4. Environment variables

Legend: **Trial** = required for recommended 5-day trial host · **Prod** = required for true `staging`/`production` cutover · **Opt** = optional.

Sources: `.env.example`, `EnvironmentValidator`, payment/email deployment docs.

### 4.1 Application / security

| Variable | Trial | Prod | Notes |
|---|---|---|---|
| `APP_ENV` | Required | Required | Trial recommendation: `uat` (see §1.3). Cutover: `production` / `staging` |
| `APP_URL` | Required | Required | Canonical `https://…` |
| `APP_DEBUG` | Required (`false`) | Required (`false`) | Warning if true on production-like |
| `APP_NAME` | Opt | Opt | Branding may override display name |
| `FORCE_HTTPS` | Required (`true`) | Required | |
| `TRUSTED_PROXIES` | Opt | Opt | Set when behind LB/proxy |
| `SESSION_COOKIE_SECURE` | Required (`true`) | Required | |
| `RATE_LIMIT_PEPPER` | Required | Required | No soft default outside local soft-secret envs |
| `TOKEN_PEPPER` | Required | Required | |
| `OTP_PEPPER` | Required | Required | Must differ from token pepper |
| `NOTIFICATION_DELIVERY_KEY` | Required | Required | 32-byte key, strict base64 |
| `NOTIFICATION_DELIVERY_KEY_PREVIOUS` | Opt | Opt | Rotation |
| `TERMS_VERSION` / `PRIVACY_VERSION` | Required | Required | Match published legal text |
| `ALLOW_LOCAL_BOOTSTRAP_ADMIN` | Required (`false`) | Required (`false`) | |
| `UAT_SEED_PASSWORD` | Opt | — | Only if deliberately seeding UAT; never for real customer DB |

### 4.2 Database

| Variable | Trial | Prod | Notes |
|---|---|---|---|
| `DB_HOST` | Required | Required | |
| `DB_PORT` | Required | Required | Default 3306 |
| `DB_NAME` | Required | Required | |
| `DB_USER` | Required | Required | |
| `DB_PASSWORD` | Required | Required | |
| `DB_CHARSET` | Required | Required | `utf8mb4` |

### 4.3 Branding

| Variable | Trial | Prod | Notes |
|---|---|---|---|
| `ACADEMY_NAME` | Required | Required | Defaults toward `APP_NAME` if unset |
| `ACADEMY_LOGO_URL` | Required | Required | Same-origin path or HTTPS URL (CSP allows configured HTTPS logo host) |
| `ACADEMY_PRIMARY_COLOR` | Required | Required | `#RRGGBB` |
| `ACADEMY_SUPPORT_EMAIL` | Required | Required | |
| `ACADEMY_CERTIFICATE_ISSUER_NAME` | Required | Required | Defaults to academy name |

### 4.4 Payments (Razorpay)

| Variable | Trial | Prod | Notes |
|---|---|---|---|
| `RAZORPAY_KEY_ID` | Required* | Required | *Required whenever fake gateway is off |
| `RAZORPAY_KEY_SECRET` | Required* | Required | Server only |
| `RAZORPAY_WEBHOOK_SECRET` | Required* | Required | Dashboard webhook secret |
| `PAYMENTS_FAKE_GATEWAY` | Required (`0`) | Required (`0`/empty) | Forbidden in staging/production if enabled |
| `PAYMENTS_RECONCILE_PENDING_STALE_SECONDS` | Opt | Opt | Default 1800 |

### 4.5 Email

| Variable | Trial | Prod | Notes |
|---|---|---|---|
| `MAIL_DRIVER` | Required (`smtp` or `ses`) | Required | Overrides legacy adapter when set |
| `MAIL_HOST` | Required | Required | With SMTP driver |
| `MAIL_PORT` | Required | Required | e.g. 587 |
| `MAIL_ENCRYPTION` | Opt | Opt | `tls` / `ssl` / `none` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | Required | Required | Provider credentials |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | Required | Required | Verified sender |
| `NOTIFICATION_EMAIL_ADAPTER` | Opt | Must not be `local_file`/`recording` | Staging/production reject those |
| `NOTIFICATION_SMS_ADAPTER` | Opt | Opt | SMS OTP pack incomplete (`PR-SMS`) |
| `NOTIFICATION_LOCAL_MAIL_PATH` | — | — | Local demo only |

### 4.6 Storage / documents

| Variable | Trial | Prod | Notes |
|---|---|---|---|
| `DOCUMENTS_STORAGE_DRIVER` | Required (`local` only if `APP_ENV` allows) | **Blocked today** | No S3 driver in-repo; production-like forbids `local` |
| `DOCUMENTS_LOCAL_BASE_PATH` | Required if local | — | Default `storage/documents` |
| `DOCUMENTS_LOCAL_SIGNING_SECRET` | Required if local | — | |
| `DOCUMENTS_FAKE_SCANNER` | Explicit `1` only on trial/`uat` | **Forbidden** | No real scanner class |
| `DOCUMENTS_DECLARATION_VERSION` | Required | Required | |
| `DOCUMENTS_UPLOAD_TTL_SECONDS` / `DOWNLOAD_TTL_SECONDS` | Opt | Opt | Defaults 900 |
| Scan SLA / lease knobs | Opt | Opt | See `.env.example` |

### 4.7 Logging / outbox

| Variable | Trial | Prod | Notes |
|---|---|---|---|
| `LOG_LEVEL` | Required (`info`/`warning`) | Required | Avoid `debug` on public hosts |
| `LOG_PATH` | Required | Required | Default `storage/logs/app.log` |
| `OUTBOX_TRANSPORT` | Required for reliable mail | Required | Must not silently stay unconfigured if email must leave |
| Outbox lease/backoff knobs | Opt | Opt | Defaults in `.env.example` |

---

## 5. Background jobs and workers

CLI entry: `php bin/jobs.php <command>` (`bin/jobs.php`). Jobs are **finite batches** — schedule with cron or systemd timers (≥ 1 minute). Examples: [PROCESS_SUPERVISION_EXAMPLES.md](../operations/PROCESS_SUPERVISION_EXAMPLES.md).

| Command | Purpose | Cadence | Required for trial? |
|---|---|---|---|
| `payment:webhook-process` | Process durable Razorpay webhook receipts → payment/admission flow | Every 1 min | **Yes** (real Razorpay) |
| `payment:reconcile` | Reconcile stale/pending payments | Every 5 min | **Yes** |
| `outbox:relay` | Publish outbox messages | Every 1 min | **Yes** if email/outbox used |
| `notification:deliver` | Identity + transactional email delivery (incl. certificate issued) | Every 1 min | **Yes** if email required |
| `document:scan` | Claim submissions pending malware scan | Every 1 min | **Yes** if Mode A documents used |
| `document:stuck-scan` | Stuck-scan watchdog | Every 5 min | **Yes** if documents used |
| `session:cleanup` | Expire sessions | Every 5–15 min | **Yes** |
| `rate-limit:cleanup` | Expire rate-limit rows | Every 5–15 min | **Yes** |
| `token-confirmation:cleanup` | Purge confirmation contexts | Hourly | **Yes** |
| `uat:seed` / `uat:reset` | UAT personas | Never on schedule | **No** — ops-only, gated |
| `demo:prepare` / `demo:process` / `demo:payment-capture` | Local demo helpers | Never on schedule | **No** on customer host |

### Sample cron (customize user/path/PHP)

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

Ensure cron inherits the same env file as PHP-FPM.

**Architecture reminder:** Real Razorpay path is webhook HTTP ingress → durable event → `payment:webhook-process` → existing payment/admission state machines. Browser “Confirming payment…” is informational. In-process webhook processing after demo capture exists **only** for fake-gateway demo (`DemoPaymentSimulationService`) and must stay off on the customer host.

---

## 6. Storage

### 6.1 Writable directories (repository layout)

Under `storage/` (also present in tree): `logs`, `documents`, `mail`, `cache`, `sessions`, `tmp`, `uploads`, `backups`.

| Path | Purpose |
|---|---|
| `storage/` | Root checked by readiness / `EnvironmentValidator` |
| `storage/logs/` | `LOG_PATH` default |
| `storage/documents/` | Local object storage for credential documents (when local driver allowed) |
| `storage/mail/` | `local_file` email only (not for production-like) |
| Others | Runtime scratch |

Certificates: **generated on demand** (Dompdf HTML→PDF); metadata in MySQL `certificates` table — **no dedicated certificate file store** in Phase 1.

### 6.2 Uploaded content

- Credential documents: private object storage abstraction; **local disk** implementation only for non-production-like envs.  
- Learning PDFs: content type exists; learner download/media pipeline is incomplete (placeholder UX).  
- Video: **external URL / embed only** — no uploaded video blobs.

### 6.3 Limitations (current repository)

| Topic | Status |
|---|---|
| Local storage | Implemented (`LocalObjectStorage`); allowed only when env permits fake/local |
| S3 readiness | **Not implemented** in `ObjectStorageFactory` — falls through to `UnconfiguredObjectStorage` |
| Malware scanner | Fake + unconfigured only |
| Backups | UAT `mysqldump` rehearsal docs/scripts exist; production DR not certified (`PR-BACKUP`) |

### 6.4 Backup considerations (trial minimum)

1. Nightly logical DB dump + checksum to non-web disk or object storage.  
2. If using local documents, back up `storage/documents`.  
3. Rehearse restore once before day 1 when possible.  
4. See [BACKUP_RESTORE_RUNBOOK.md](../operations/BACKUP_RESTORE_RUNBOOK.md) (UAT-oriented).

---

## 7. External services

### 7.1 Razorpay

| Item | Requirement |
|---|---|
| Keys | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET` |
| Webhook secret | `RAZORPAY_WEBHOOK_SECRET` |
| Webhook URL | `https://<customer-domain>/webhooks/razorpay` |
| Events | At least `payment.captured`, `payment.failed`; recommended `order.paid`, `payment.authorized` |
| Mode | Test keys for private rehearsal; live keys only when taking real money |
| Fake gateway | Must be **off** on customer host |

Worker: `payment:webhook-process` (+ `payment:reconcile`). Details: [RAZORPAY_CONFIGURATION.md](./RAZORPAY_CONFIGURATION.md).

### 7.2 Email provider

| Item | Requirement |
|---|---|
| Driver | `MAIL_DRIVER=smtp` or `ses` |
| Host/auth/from | Verified domain or address (SES sandbox limits apply until production access) |
| Workers | `outbox:relay` + `notification:deliver` |
| Forbidden on staging/production | `local_file`, `recording` adapters |

Details: [EMAIL_CONFIGURATION.md](./EMAIL_CONFIGURATION.md).

### 7.3 Domain / SSL

1. DNS A/AAAA (or CNAME) → VPS / LB.  
2. Issue certificate (Let’s Encrypt, Hostinger SSL, ACM, etc.).  
3. Set `APP_URL` to canonical HTTPS origin.  
4. Enable HTTPS redirect + `FORCE_HTTPS` / secure cookies.  
5. Confirm webhook and mail provider can reach/accept the public hostname.

Health:

```bash
curl -sS https://<domain>/health/live
curl -sS https://<domain>/health/ready
```

---

## 8. First customer trial deployment checklist

Before giving the customer access:

### Platform

- [ ] Domain resolves to this host  
- [ ] SSL active; HTTP redirects to HTTPS  
- [ ] Deployed app commit is `31363c5` (or approved tag)  
- [ ] `GET /health/live` and `/health/ready` return 200  
- [ ] PHP-FPM + Nginx/Apache serve only `public/`  
- [ ] Cron/systemd workers running (payment, outbox, notifications, documents as applicable)  
- [ ] Nightly DB backup configured and one restore rehearsal noted  

### Branding & admin

- [ ] Academy branding configured (name, logo, colour, support email, certificate issuer)  
- [ ] Admin / Course Admin account created (no UAT demo password reuse)  
- [ ] Fake payment gateway off; bootstrap admin flags off  

### Course / learning

- [ ] Course loaded (catalogue + published version + open batch with `starts_at` in the past for Active enrolment)  
- [ ] Video lesson tested (embedded YouTube/Vimeo or external HTTPS link)  
- [ ] Text lesson / Mark complete tested  
- [ ] Assessment attempt pass path tested  
- [ ] Certificate view + PDF + public verify tested  

### Money & mail

- [ ] Razorpay test or live checkout tested end-to-end through webhook worker → Admitted + Enrolment  
- [ ] Email delivery tested (registration or transactional) with SMTP/SES  

### Documents (if Mode A docs in scope)

- [ ] Storage/scanner posture agreed (`uat`+local+fake **or** accept blocked uploads on production-like)  
- [ ] Upload → scan worker → reviewer path tested under that posture  
- [ ] Finance SoD: finance cannot open document URLs  

### Sign-off

| Role | Name | Date | Ack |
|---|---|---|---|
| Deployer | | | ☐ |
| Facilitator | | | ☐ |
| Product Owner | | | ☐ |

---

## 9. Known limitations for first customer trial

Intentionally deferred / incomplete in the repository (do not promise these):

| Area | Limitation |
|---|---|
| Object storage | **No S3 adapter**; production-like cannot use `local` |
| Malware scanning | **No real scanner**; fake only on non-production-like envs |
| MFA | Privileged MFA enrol/challenge UI incomplete (AGENTS expects MFA) |
| Video hosting | No upload/transcode/CDN/DRM/Mux — external embed/link only |
| PDF lessons | Type exists; learner download/viewer placeholder |
| Assessment polish | No autosave; timers/cooldown not fully exposed in admin UI |
| Analytics / watch-time | Not implemented |
| Refunds / cancel application | Product deferrals (`PR-REFUND`, `PR-CANCEL`) |
| SMS OTP | Provider pack incomplete (`PR-SMS`) |
| Multi-tenancy | Single-deployment branding only |
| Production register drift | Some rows still label player/assess/cert as “future” while Phase 1 code exists — trust code + this guide for trial scope |
| Alerting / load / pen-test | `PR-ALERT`, `PR-LOAD`, `PR-SEC` open |
| Demo in-process payment capture | Fake-gateway only — not for customer hosts |

---

## 10. Deployment recommendation

### Recommended setup — first 5-day customer trial

| Choice | Recommendation |
|---|---|
| Host | Single Ubuntu 22.04/24.04 VPS/EC2 (2 vCPU / 4 GB) |
| App commit | `31363c5` |
| `APP_ENV` | **`uat`** for Mode A+documents trial with explicit local storage + fake scanner; **real** Razorpay + **real** SMTP |
| Web | Nginx → `public/` + Let’s Encrypt (or panel SSL) |
| Workers | Cron/systemd minute jobs for webhook, outbox, notifications, document scan |
| Data | No UAT persona seed on the customer’s real data; Course Admin builds the trial course |
| Payments | Razorpay test mode until go-live moment; then live keys + live webhook |
| Risk acceptance | Written acceptance that local docs + fake scanner are **temporary trial compromises**, not production |

Alternative: `APP_ENV=production` with real Razorpay/SMTP **only if** document upload is out of trial scope (storage/scanner unconfigured).

### Recommended future production setup

| Choice | Recommendation |
|---|---|
| `APP_ENV` | `production` |
| Storage | Implement/deploy private S3 pack (`PR-S3`) before enabling credential uploads |
| Scanner | Real malware scanner (`PR-MALWARE`) |
| Email | SES/SMTP with verified domain; workers supervised |
| Payments | Live Razorpay + monitored `payment:webhook-process` / reconcile |
| Ops | Alerting (`PR-ALERT`), backup/DR (`PR-BACKUP`), MFA for privileged roles, pen-test (`PR-SEC`) |
| Hosting | Approved architecture host (`PR-HOST`) |

Do not claim production cutover until register pilot/production rows above are closed or explicitly waived in the Decision Log.

---

## Appendix — Health & smoke commands

```bash
curl -sS "$APP_URL/health/live"
curl -sS "$APP_URL/health/ready"
php8.4 -v
php8.4 -m | grep -E 'pdo_mysql|mbstring|json|sodium|openssl|curl|gd'
php8.4 vendor/bin/phinx status -c phinx.php
```

---

*Documentation only. No application code, migrations, or architecture changes in this file’s production.*
