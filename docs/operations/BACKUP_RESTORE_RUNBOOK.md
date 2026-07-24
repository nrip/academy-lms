# Backup and Restore Runbook — Academy LMS (UAT)

**Authority:** RC-01 section U  
**Method:** `mysqldump`-based logical backup suitable for **UAT rehearsal**  
**Scripts:** `bin/backup-uat.sh`, `bin/restore-rehearsal.sh`

This runbook does **not** certify production backup/DR readiness. Production requires approved hosting, encryption, retention, and restore RTO/RPO (see [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) `PR-BACKUP`).

---

## Principles

| Rule | Detail |
|---|---|
| No credentials in shell history | Prefer defaults file / env files with restricted permissions; avoid inline `-pPassword` |
| Consistent method | Same dump flags for backup and rehearsal |
| Checksum | Record SHA-256 (or stronger) of artifact |
| Encryption at rest | Encrypt backup artifacts on disk/object storage (age/gpg/KMS) — **recommended**, environment-specific |
| Restore target | Always a **separate** test database name — never overwrite active UAT blindly |
| Verify after restore | Migrations/schema sanity + readiness + smoke |
| Cleanup | Drop rehearsal DB and delete local artifacts when done |

---

## Prerequisites

- MySQL 8.4 client tools (`mysqldump`, `mysql`)
- Access to UAT DB credentials via secure env (`DB_*` / defaults file)
- Application checkout matching the backed-up schema generation
- Enough disk for dump + restore

---

## Backup (UAT)

### Implemented script

```bash
# From repository root — see script header for flags
./bin/backup-uat.sh
```

Expected behaviour (RC-01):

1. Read connection settings from environment / defaults file (not argv password).
2. `mysqldump` with InnoDB-consistent options appropriate to MySQL 8.4.
3. Write timestamped artifact under a configured backup directory (outside web root).
4. Emit checksum file alongside the dump.
5. Print artifact path + checksum only (no credentials).

### Manual equivalent (example)

```bash
# EXAMPLE — adjust paths; prefer --defaults-extra-file=./.my.uat.cnf
mysqldump --defaults-extra-file=./.my.uat.cnf \
  --single-transaction --routines --triggers --hex-blob \
  --set-gtid-purged=OFF \
  "$DB_NAME" \
  | gzip -c > "/secure/backups/uat-${DB_NAME}-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"

shasum -a 256 "/secure/backups/uat-….sql.gz" > "/secure/backups/uat-….sql.gz.sha256"
```

Store `.my.uat.cnf` mode `0600`. Do not commit it.

---

## Restore rehearsal

### Implemented script

```bash
./bin/restore-rehearsal.sh
```

Expected sequence (RC-01):

1. Confirm `APP_ENV` allows destructive rehearsal against a **named test DB** (not production).
2. Seed or use current UAT DB as source (or accept an existing dump path).
3. Create backup via `backup-uat.sh` (or consume provided artifact + verify checksum).
4. Create empty target database `…_rehearsal` (name from env).
5. Restore dump into target.
6. Point a temporary app config / `DB_NAME` at the rehearsal DB **or** run verification SQL only.
7. Verify key invariants / row-count smoke (applications, payments, enrolments uniqueness where applicable).
8. Run `GET /health/live` and `GET /health/ready` against the rehearsal-configured app when applicable.
9. Run a short smoke subset (login page, health, optional PHPUnit smoke if CI-safe).
10. Report pass/fail; drop rehearsal DB; optional artifact retention note.

### Manual verification queries (examples)

```sql
-- EXAMPLE checks after restore — adjust to schema
SELECT COUNT(*) AS users FROM users;
SELECT COUNT(*) AS applications FROM applications;
SELECT application_id, COUNT(*) FROM enrolments GROUP BY application_id HAVING COUNT(*) > 1;
```

Enrolment must remain at most one per application.

---

## Application smoke after restore

```bash
curl -sS -o /dev/null -w "%{http_code}\n" "$APP_URL/health/live"
curl -sS -o /dev/null -w "%{http_code}\n" "$APP_URL/health/ready"
# Optional: php bin/setup.php  (must not mutate wrong DB)
```

---

## Cleanup

```bash
# EXAMPLE
mysql --defaults-extra-file=./.my.uat.cnf -e "DROP DATABASE IF EXISTS \`${DB_NAME}_rehearsal\`;"
# Secure-delete or encrypt+archive dumps per retention policy
```

---

## Failure handling

| Symptom | Action |
|---|---|
| Checksum mismatch | Do not restore; re-backup; investigate disk/transit |
| Restore errors on views/routines | Align MySQL version; re-dump with same flags |
| Readiness fails after restore | Check migrations table vs code; credentials; paths |
| Accidental restore to wrong DB | Stop; restore from last known good; incident note |

Alert references: `ALT-BACKUP-FAIL`, `ALT-RESTORE-REHEARSAL-FAIL` in [ALERT_CATALOGUE.md](./ALERT_CATALOGUE.md).

---

## What this proves / does not prove

| Proves (UAT) | Does not prove |
|---|---|
| Dump/restore tooling works for this schema generation | Production PITR, cross-region DR |
| Basic invariants survive logical backup | Zero-downtime backup under peak load |
| Operators can rehearse without tribal knowledge | Encrypted offsite retention compliance |
