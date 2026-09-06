# UAT Deployment Runbook — Academy LMS (RC-01)

**Authority:** RC-01 section V  
**Audience:** Engineers deploying a UAT (or local dry-run) release candidate  
**Related:** [RC01_RELEASE_CHECKLIST.md](../releases/RC01_RELEASE_CHECKLIST.md), [WORKERS_AND_SCHEDULES.md](./WORKERS_AND_SCHEDULES.md), [BACKUP_RESTORE_RUNBOOK.md](./BACKUP_RESTORE_RUNBOOK.md)

This runbook describes **application** deployment steps implemented or scripted in-repo. Host provisioning, load balancers, and AWS account layout are **environment-specific** and not invented here.

---

## 1. Pre-deployment checks

| Check | Pass criteria |
|---|---|
| Target `APP_ENV` | `uat` (or `local` for dry-run) — not accidental `production` |
| Commit / tag | Matches RC-01 checklist approved SHA |
| CI | Green on that commit |
| Migrations reviewed | Forward migrations only; know rollback limitations |
| Secrets present | Peppers, delivery keys, DB, webhook secrets via env — **no soft defaults in uat** |
| Adapter policy | Explicit fake/local flags if used; documented in change ticket |
| Backup | Fresh UAT backup taken ([BACKUP_RESTORE_RUNBOOK.md](./BACKUP_RESTORE_RUNBOOK.md)) |

---

## 2. Artifact / commit selection

```bash
git fetch --prune
git checkout <approved-tag-or-sha>
git rev-parse HEAD   # record in sign-off
```

Deploy the same tree CI tested. Do not mix uncommitted local changes on shared UAT.

---

## 3. Maintenance considerations

| Mode | When |
|---|---|
| Soft | Brief worker pause; app may stay up for read-mostly checks |
| Hard | Schema migrations that lock tables — communicate UAT freeze window |

UAT usually accepts short freezes. Do not claim zero-downtime unless your host stack provides it.

---

## 4. Environment setup

1. Set `APP_ENV=uat`.
2. Provide `.env` only for `local|testing|ci|uat` (dotenv load allowed). Staging/production remain fail-closed without dotenv file load.
3. Confirm `EnvironmentValidator` / `php bin/setup.php` accepts config.
4. Confirm fake adapters are **explicit** if used (`PAYMENTS_FAKE_GATEWAY`, `DOCUMENTS_FAKE_SCANNER`, `DOCUMENTS_STORAGE_DRIVER=local`, email adapter, etc.).

---

## 5. Dependency installation

```bash
composer install --no-dev --optimize-autoloader   # or with --dev if UAT needs test tools
# PHP 8.4 required
```

Record Composer lock hash in the deploy note.

---

## 6. Frontend assets

```bash
node -v    # CI pins Node 22 LTS for RC-01
npm ci
node bin/install-frontend-assets.mjs
# or repository npm script equivalent
```

---

## 7. Migrations

```bash
php vendor/bin/phinx migrate
php vendor/bin/phinx status
```

- Never hand-edit production/UAT schema.
- Prefer expand/contract for risky changes; UAT may still take short locks.
- **Do not** run destructive `phinx rollback` on a shared UAT DB with precious exploratory data unless the cycle agrees to reset.

---

## 8. Cache / bootstrap behaviour

- PHP opcache: reload/restart php-fpm or container after deploy so new code is visible.
- Application has no assumed external cache cluster in-repo — do not invent Redis flush steps unless your host uses one.
- Writable paths: `storage/` (logs, mail, local documents as configured).

```bash
php bin/setup.php          # verify without seed
php bin/setup.php --seed-uat   # only when intentionally reseeding
```

---

## 9. Worker restart order

1. Stop scheduled runners / long workers (systemd/Supervisor) — see [PROCESS_SUPERVISION_EXAMPLES.md](./PROCESS_SUPERVISION_EXAMPLES.md).
2. Deploy code + migrate.
3. Restart web (php-fpm / unit).
4. Start workers in this order:
   1. `outbox:relay`
   2. `notification:deliver`
   3. `document:scan` (+ `document:stuck-scan` on its timer)
   4. `payment:webhook-process`
   5. `payment:reconcile`
   6. Cleanup timers (`session:cleanup`, `rate-limit:cleanup`, `token-confirmation:cleanup`)
5. Confirm locks are not stuck from killed processes (TTL 120s for cleanup locks).

**Never schedule** `uat:seed` / `uat:reset`.

---

## 10. Readiness verification

```bash
curl -sS "$APP_URL/health/live"
curl -sS "$APP_URL/health/ready"
```

Expect HTTP 200 and JSON without secrets. Optional build metadata (version, commit, schema) may appear on readiness only.

---

## 11. Smoke test

Minimum:

- Login page loads
- `/health/ready` 200
- One seeded persona login (after MFA if privileged)
- One worker command prints a summary line

Full UAT scripts are separate: [UAT_OVERVIEW.md](../uat/UAT_OVERVIEW.md).

---

## 12. Rollback decision points

| Point | Action |
|---|---|
| Before migrate | Redeploy previous artifact; no schema undo needed |
| After migrate, before seed | Prefer forward fix; rollback migration **only** if migration is reversible and UAT cycle approves data loss risk |
| After UAT data changes | Do not destructive-rollback schema; restore DB from backup into a clean cycle if required |
| Worker-only regression | Revert code; keep schema if compatible |

**Limitation:** Migrated schema + new rows may make code rollback unsafe. Prefer fix-forward on shared UAT.

---

## 13. Post-deployment monitoring

Watch for 30–60 minutes (UAT scale):

- Readiness flake
- 5xx
- Outbox / webhook / notification backlogs ([ALERT_CATALOGUE.md](./ALERT_CATALOGUE.md))
- Disk use under `storage/`

---

## 14. Sign-off

Record in [UAT_SIGNOFF_TEMPLATE.md](../uat/UAT_SIGNOFF_TEMPLATE.md) and [RC01_RELEASE_CHECKLIST.md](../releases/RC01_RELEASE_CHECKLIST.md):

- SHA
- Migrator
- Backup artifact id/checksum
- Ready probe evidence
- Residual readiness-register items

---

## Commands quick reference

```text
php bin/setup.php [--seed-uat]
php bin/jobs.php uat:seed
php bin/jobs.php uat:reset --confirm
php bin/jobs.php session:cleanup | rate-limit:cleanup | outbox:relay
php bin/jobs.php notification:deliver | token-confirmation:cleanup
php bin/jobs.php document:scan | document:stuck-scan
php bin/jobs.php payment:webhook-process | payment:reconcile
GET /health/live
GET /health/ready
```
