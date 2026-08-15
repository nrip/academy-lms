# Mode A demo — local run instructions

**Audience:** Product Owner and facilitators  
**Branch:** `demo/mode-a-user-demo`  
**Goal:** Open the real application in a browser, log in as each persona, and demonstrate the Mode A admissions journey.

This is not a disconnected prototype. It uses the merged application, database, state machines, permissions, webhook ingress, and Enrolment workflow.

---

## Prerequisites

- PHP 8.4+
- Composer
- MySQL 8.x running locally
- Node.js (for frontend vendor assets)

### PHP upload limits (required)

Credential documents may be up to **10 MB**. The PHP process must allow that:

| Setting | Minimum |
|---|---|
| `upload_max_filesize` | `10M` |
| `post_max_size` | `16M` (multipart overhead) |

`demo:prepare` refuses to continue when the active PHP runtime is below these limits.

**Always start the web server with the repository wrapper** (sets the flags for you):

```bash
composer demo-serve
# equivalent: bash bin/demo-server.sh
```

Do **not** use a bare `php -S …` unless you pass the same `-d` flags.

---

## Exact startup commands

Run from the repository root.

### 1. Check out the demo branch

```bash
git fetch origin
git checkout demo/mode-a-user-demo
```

(Or clone the repository, then check out this branch.)

### 2. Create the environment file

```bash
cp .env.example .env
```

Edit `.env` and set at least:

```bash
APP_ENV=local
APP_URL=http://127.0.0.1:8080
APP_DEBUG=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=academy_lms
DB_USER=academy
DB_PASSWORD=your_local_password

PAYMENTS_FAKE_GATEWAY=1
DOCUMENTS_STORAGE_DRIVER=local
DOCUMENTS_FAKE_SCANNER=1
NOTIFICATION_EMAIL_ADAPTER=local_file

# Optional override (default shown in demo:prepare output)
# UAT_SEED_PASSWORD=Uat-Demo-Passw0rd!
```

Create the MySQL database and user if they do not exist:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS academy_lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE USER IF NOT EXISTS 'academy'@'localhost' IDENTIFIED BY 'your_local_password';"
mysql -u root -e "GRANT ALL ON academy_lms.* TO 'academy'@'localhost'; FLUSH PRIVILEGES;"
```

### 3. Start MySQL

Ensure mysqld is running (example on macOS Homebrew):

```bash
brew services start mysql
mysqladmin ping -h 127.0.0.1
```

### 4. Install dependencies

```bash
composer install --no-interaction --prefer-dist
composer assets:install
```

### 5. Run migrations and prepare the demo

```bash
php bin/jobs.php demo:prepare --confirm --migrate
```

Prefer the Composer wrapper (raises PHP upload limits for the CLI process):

```bash
composer demo-prepare -- --migrate
```

`--migrate` runs Phinx migrations. Omit it on later runs if the schema is already current:

```bash
composer demo-prepare
```

`demo:prepare` refuses to continue when PHP `upload_max_filesize` / `post_max_size` are below the 10 MB application document cap. Use `composer demo-prepare` or pass `-d upload_max_filesize=10M -d post_max_size=16M`.
The command is idempotent. It prints:

- application URL
- persona emails
- demo password (from `UAT_SEED_PASSWORD` or the documented default)
- post-login landings

### 6. Start the PHP web server

```bash
composer demo-serve
```

This runs `bin/demo-server.sh`, which starts:

`php -d upload_max_filesize=10M -d post_max_size=16M -S 127.0.0.1:8080 -t public`

Open: [http://127.0.0.1:8080/login](http://127.0.0.1:8080/login)

Keep that terminal open while demonstrating.

### 7. Process demo jobs (after uploads / demo payment)

```bash
php bin/jobs.php demo:process
```

Safe to re-run. Runs document scan → outbox (if configured) → webhook processing → payment reconciliation → notification delivery.

### 8. Log in

| Persona | Email | Landing |
|---|---|---|
| Learner | `learner@uat.example.test` | `/dashboard` |
| Reviewer | `reviewer@uat.example.test` | `/reviewer/applications` |
| Finance | `finance@uat.example.test` | `/finance/reconciliation` |
| Notification Ops | `ops@uat.example.test` | Open **Notifications** from nav (`/admin/notifications`). Super Admin may land on Reviewer Queue first. |

**Password:** value printed by `demo:prepare`, or default `Uat-Demo-Passw0rd!` when `UAT_SEED_PASSWORD` is unset.

Follow [`DEMO_SCRIPT.md`](./DEMO_SCRIPT.md) for the 15–20 minute walkthrough.

---

## Useful companion commands

```bash
php bin/jobs.php demo:process
php bin/jobs.php demo:payment-capture {paymentId}   # CLI capture + process
php bin/jobs.php uat:reset --confirm                # wipe @uat.example.test rows
php bin/jobs.php demo:prepare --confirm             # reseed after reset
```

---

## Related documents

- [`DEMO_SCRIPT.md`](./DEMO_SCRIPT.md) — presenter script
- [`DEMO_READINESS_CHECKLIST.md`](./DEMO_READINESS_CHECKLIST.md) — pre-demo gate
- [`USER_FEEDBACK_TEMPLATE.md`](./USER_FEEDBACK_TEMPLATE.md) — capture feedback
- [`../uat/UAT_ACCOUNTS.md`](../uat/UAT_ACCOUNTS.md) — full persona notes
