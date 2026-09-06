# UAT Overview — Academy LMS (RC-01)

**Package:** RC-01 — UAT Release and Deployment Hardening  
**Scope:** Mode A vertical slice (registration → profile → application → documents → review → payment → admission → dashboard + transactional notifications).  
**Out of scope:** Course player, assessments, certificates, refund automation, production AWS/SES/SMS packs.

This overview is the entry point for manual UAT. Scripts assume a seeded UAT database and workers that can be run on demand.

---

## Related documents

| Document | Purpose |
|---|---|
| [UAT_ACCOUNTS.md](./UAT_ACCOUNTS.md) | Personas, credentials policy, MFA bootstrap, reset |
| [UAT_LEARNER_JOURNEY.md](./UAT_LEARNER_JOURNEY.md) | Learner cases (section J) |
| [UAT_REVIEWER_JOURNEY.md](./UAT_REVIEWER_JOURNEY.md) | Reviewer cases (section K) |
| [UAT_FINANCE_JOURNEY.md](./UAT_FINANCE_JOURNEY.md) | Finance cases (section L) |
| [UAT_NOTIFICATION_OPERATIONS.md](./UAT_NOTIFICATION_OPERATIONS.md) | Notification ops cases (section M) |
| [UAT_SECURITY_NEGATIVE_TESTS.md](./UAT_SECURITY_NEGATIVE_TESTS.md) | Negative / security cases (section N) |
| [UAT_DEFECT_LOG_TEMPLATE.md](./UAT_DEFECT_LOG_TEMPLATE.md) | Defect log sheet |
| [UAT_SIGNOFF_TEMPLATE.md](./UAT_SIGNOFF_TEMPLATE.md) | Sign-off record |
| [../operations/UAT_DEPLOYMENT_RUNBOOK.md](../operations/UAT_DEPLOYMENT_RUNBOOK.md) | Deploy / verify / rollback |
| [../operations/WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md) | Job cadence and locks |
| [../releases/RC01_RELEASE_CHECKLIST.md](../releases/RC01_RELEASE_CHECKLIST.md) | Release gate checklist |
| [../product/PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) | Gaps still open after UAT |

---

## Environment modes

| `APP_ENV` | Dotenv file | Soft secret defaults | Fake / local adapters | UAT seed / reset |
|---|---|---|---|---|
| `local` | Yes | Yes | Yes (explicit flags / defaults) | Yes |
| `testing` | Yes | Yes | Yes | Yes |
| `ci` | Yes | Yes | Yes | Yes |
| `uat` | Yes | **No** — set key material explicitly | Yes **only** with explicit flags/drivers | Yes |
| `staging` | No | No | **Fail closed** | No |
| `production` | No | No | **Fail closed** | No |

**Policy note:** `uat` is deliberately **not** production-like for adapter policy. UAT may use fake payment gateway, fake malware scanner, and local object storage when those drivers/flags are set. Staging and production must not.

---

## Defect severity

| Severity | Definition | Examples |
|---|---|---|
| **Blocker** | UAT cannot continue; core Mode A path broken or data integrity at risk | Cannot register/login; cannot admit after valid payment; Enrolment created before Admitted |
| **Critical** | Major path fails for a primary persona; security or money-handling defect | Finance can open document URLs; webhook accepts unsigned payload; duplicate capture creates second Enrolment |
| **Major** | Important feature wrong or incomplete; workaround painful | Reviewer claim races incorrectly; reconciliation retry never progresses; dashboard omits Active Enrolment |
| **Minor** | Incorrect but limited impact; clear workaround | Misleading label; pagination edge case; non-blocking validation copy |
| **Cosmetic** | Visual / copy only; no functional or security impact | Spacing, typo, non-misleading wording |

**Override:** Security, segregation-of-duties, payment integrity, and state-machine violations are treated as **Critical** (or Blocker) even if UI impact looks small.

---

## Release rules (UAT exit)

| Rule | Requirement |
|---|---|
| Blocker / Critical | **None open** at UAT sign-off |
| Major | Product Owner disposition required (fix, defer with risk note, or reject release) |
| Minor / Cosmetic | May defer if logged in the defect log and linked from sign-off |
| Security / data integrity | Override normal severity; must be fixed or explicitly waived by Security + Product |
| Known gaps | Must appear on [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) — do not silently close |

---

## How to run UAT

### 1. Prerequisites

- PHP 8.4 with required extensions; Composer dependencies installed
- MySQL 8.4 database empty or reset for UAT
- `APP_ENV=uat` (or `local` for developer dry-runs)
- Explicit UAT secrets / peppers / delivery keys (no soft defaults in `uat`)
- Explicit fake/local adapters if not using real providers (document which)
- Frontend assets installed

### 2. Bootstrap

```bash
# Preferred one-shot (RC-01)
php bin/setup.php --seed-uat

# Or stepwise
composer install
php vendor/bin/phinx migrate
vendor/bin/phinx seed:run -s Wp02DemoCatalogueSeeder   # if catalogue not yet present
php bin/jobs.php uat:seed
```

Set password via environment (optional):

```bash
export UAT_SEED_PASSWORD='Uat-Demo-Passw0rd!'   # default if unset; UAT demo only
```

See [UAT_ACCOUNTS.md](./UAT_ACCOUNTS.md).

### 3. Health checks

```bash
curl -sS "$APP_URL/health/live"    # liveness — process up
curl -sS "$APP_URL/health/ready"   # readiness — DB, paths, adapter policy
# GET /health remains a liveness alias
```

Expect HTTP 200 when ready; readiness returns non-2xx when the app cannot serve correctly. Responses must not contain secrets or filesystem paths beyond safe build metadata.

### 4. Workers (on demand during UAT)

Ordinary UAT does **not** require direct DB edits. Run workers when a journey step depends on async processing:

```bash
php bin/jobs.php outbox:relay
php bin/jobs.php notification:deliver
php bin/jobs.php document:scan
php bin/jobs.php document:stuck-scan
php bin/jobs.php payment:webhook-process
php bin/jobs.php payment:reconcile
```

Cadence and locks: [WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md).

### 5. Execute scripts

1. Learner journey  
2. Reviewer journey  
3. Finance journey  
4. Notification operations  
5. Security negative tests  

For each case record: Result (`Pass` / `Fail` / `Blocked` / `N/A`), Defect ref, Severity.

### 6. Reset between cycles

```bash
php bin/jobs.php uat:reset --confirm
php bin/jobs.php uat:seed
```

Refuses `staging` / `production`. Ops-only — never scheduled, never HTTP-exposed.

### 7. Sign-off

Complete [UAT_SIGNOFF_TEMPLATE.md](./UAT_SIGNOFF_TEMPLATE.md) with defect disposition and checklist links.

---

## Evidence expectations

Capture enough to reproduce a defect without PII dumps:

- Request ID (`X-Request-Id` / JSON `request_id`)
- URL + HTTP status
- Persona email (UAT domain only)
- Application / payment public references (e.g. `UAT-*`)
- Screenshot or short redacted HAR for UI defects
- Worker stdout line for async failures

Do **not** paste passwords, OTP/TOTP secrets, session cookies, CSRF tokens, webhook signatures, or document contents into tickets.

---

## Invariants under test (always)

- Enrolment exists only when `Application.status = Admitted`
- Payment belongs to Application (`application_id NOT NULL`)
- Finance cannot access DocumentSubmission data or signed document URLs
- Browser payment return is informational (“Confirming…”) — webhook/worker is authoritative
- All status changes go through state machines (no raw admin status edits in UAT scripts)
