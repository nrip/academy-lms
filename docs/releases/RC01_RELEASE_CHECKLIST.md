# RC-01 Release Checklist — UAT Release and Deployment Hardening

**Package:** RC-01  
**Branch (typical):** `hardening/rc01-uat-deployment`  
**Authority:** RC-01 section W  
**Nature:** Operational hardening — not a feature release for player/assessments/certificates.

Use with [UAT_OVERVIEW.md](../uat/UAT_OVERVIEW.md), [UAT_DEPLOYMENT_RUNBOOK.md](../operations/UAT_DEPLOYMENT_RUNBOOK.md), and [UAT_SIGNOFF_TEMPLATE.md](../uat/UAT_SIGNOFF_TEMPLATE.md).

---

## Identity

| Field | Value |
|---|---|
| Approved commit / tag | |
| CI run URL | |
| Deployed `APP_ENV` | uat / local dry-run |
| Deployed at (UTC) | |
| Deployer | |

---

## Engineering gates

| # | Gate | Owner | Pass? | Notes |
|---|---|---|---|---|
| 1 | Approved commit/tag recorded | Eng | ☐ | |
| 2 | CI green (PHPUnit, PHPStan, CS, assets) | Eng | ☐ | |
| 3 | Migration test (fresh DB + latest rollback/reapply policy) | Eng | ☐ | |
| 4 | Clean install via `php bin/setup.php` | Eng | ☐ | |
| 5 | UAT seed `php bin/jobs.php uat:seed` | Eng | ☐ | |
| 6 | UAT reset gate verified (`--confirm`, refuses staging/production) | Eng | ☐ | |
| 7 | Worker commands listed in [WORKERS_AND_SCHEDULES.md](../operations/WORKERS_AND_SCHEDULES.md) runnable | Eng | ☐ | |
| 8 | `GET /health/live` → 200 | Eng | ☐ | |
| 9 | `GET /health/ready` → 200 under configured adapters | Eng | ☐ | |
| 10 | Backup + restore rehearsal | Ops/Eng | ☐ | [BACKUP_RESTORE_RUNBOOK.md](../operations/BACKUP_RESTORE_RUNBOOK.md) |
| 11 | Security negative suite executed | QA/Sec | ☐ | [UAT_SECURITY_NEGATIVE_TESTS.md](../uat/UAT_SECURITY_NEGATIVE_TESTS.md) |
| 12 | Learner / Reviewer / Finance / Notification journeys executed | QA | ☐ | |
| 13 | Flaky register reviewed | Eng | ☐ | [FLAKY_TEST_REGISTER.md](../engineering/FLAKY_TEST_REGISTER.md) |
| 14 | Production readiness register triaged (nothing silently closed) | Product/Eng | ☐ | |

---

## Known issues

| ID | Severity | Disposition | Link |
|---|---|---|---|
| | | | |

Open Blocker/Critical must be **none**. Majors require Product Owner disposition ([UAT_OVERVIEW.md](../uat/UAT_OVERVIEW.md)).

---

## Sign-off owners

| Role | Name | Date | Ack |
|---|---|---|---|
| Engineering | | | ☐ |
| QA / UAT Lead | | | ☐ |
| Product Owner | | | ☐ |
| Operations | | | ☐ |
| Security (if required) | | | ☐ |

---

## Rollback plan

| Scenario | Plan |
|---|---|
| Code defect, schema unchanged | Redeploy previous approved artifact; restart workers; re-check readiness |
| Schema migrated, fix-forward preferred | Ship forward fix; avoid destructive rollback on shared UAT |
| Data corruption in UAT | Restore from backup into new cycle DB; reseed if needed |
| Adapter misconfig | Fix env; do not “temporarily” enable fakes in staging/production |

Limitations: schema rollback after data writes may be unsafe — see deployment runbook §12.

---

## Release notes (RC-01)

### Included

- Environment capability matrix (`local|testing|ci|uat|staging|production`) with deliberate UAT fake-adapter policy
- `bin/setup.php` clean-install path; `uat:seed` / `uat:reset --confirm`
- `GET /health/live`, `GET /health/ready` (plus `/health` liveness alias)
- UAT / operations / release / engineering documentation set
- Production readiness register classification (UAT / pilot / production / future)
- UAT backup & restore rehearsal scripts (`bin/backup-uat.sh`, `bin/restore-rehearsal.sh`)
- CI hardening (Node 22, concurrency/timeouts as applicable)

### Explicitly not included

- Course player, assessments, certificates
- Production SES/SMS/AWS hosting packs
- Refund automation; SA-04 template CMS; in-app notification centre
- Monitoring product integration (catalogue only)

### Residual gaps

See [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) — all rows remain open until explicitly delivered.

---

## Final decision

☐ **RC-01 approved for UAT handoff**  
☐ **Approved with documented deferrals**  
☐ **Not approved**
