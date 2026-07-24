# Alert Catalogue — Academy LMS (RC-01)

**Authority:** RC-01 section T  
**Status:** Suggested operational alerts. **No claim is made that a monitoring product is wired** unless your environment has integrated one.

Use with [WORKERS_AND_SCHEDULES.md](./WORKERS_AND_SCHEDULES.md), [UAT_DEPLOYMENT_RUNBOOK.md](./UAT_DEPLOYMENT_RUNBOOK.md), and [BACKUP_RESTORE_RUNBOOK.md](./BACKUP_RESTORE_RUNBOOK.md).

Severity here is operational (Pager vs ticket), distinct from UAT defect severity.

---

## Catalogue

### ALT-READY-01 — Readiness failure

| Field | Value |
|---|---|
| **Signal / query** | `GET /health/ready` non-2xx, or synthetic check failing |
| **Threshold (placeholder)** | ≥1 failure in 2 consecutive checks (1–2 min apart) |
| **Severity** | Page (prod/staging); Ticket (UAT) |
| **Owner** | Platform / on-call |
| **Runbook** | [UAT_DEPLOYMENT_RUNBOOK.md](./UAT_DEPLOYMENT_RUNBOOK.md) — verify DB, paths, adapter/key config |
| **False positives** | Rolling deploys; intentional maintenance; mis-pointed synthetic URL |

### ALT-HTTP-5XX — Repeated 5xx

| Field | Value |
|---|---|
| **Signal / query** | Web/app 5xx rate (exclude health if noisy) |
| **Threshold** | >1% of requests **or** >N absolute / 5 min (tune) |
| **Severity** | Page if sustained 5+ min |
| **Owner** | Application on-call |
| **Runbook** | Check logs by `request_id`; recent deploy; dependency outage |
| **False positives** | Single bad bot path; intentional chaos tests |

### ALT-DB-DOWN — Database unavailable

| Field | Value |
|---|---|
| **Signal / query** | Readiness DB check fail; connection errors in app logs |
| **Threshold** | Any sustained >1 min |
| **Severity** | Page |
| **Owner** | Platform / DBA |
| **Runbook** | Network, credentials, MySQL process, disk |
| **False positives** | Brief failover if HA exists (not assumed implemented) |

### ALT-OUTBOX-BACKLOG — Outbox backlog

| Field | Value |
|---|---|
| **Signal / query** | Count of outbox messages pending/processing beyond age SLA |
| **Threshold** | Placeholder: >100 pending **or** oldest >15 min |
| **Severity** | Ticket → Page if oldest >60 min |
| **Owner** | Application |
| **Runbook** | [WORKERS_AND_SCHEDULES.md](./WORKERS_AND_SCHEDULES.md) `outbox:relay`; transport config |
| **False positives** | Transport intentionally unconfigured in local/UAT |

### ALT-WEBHOOK-BACKLOG — Webhook processing backlog

| Field | Value |
|---|---|
| **Signal / query** | Unprocessed payment webhook events age/count |
| **Threshold** | Placeholder: oldest >10 min or count >50 |
| **Severity** | Page in pilot/production (payments) |
| **Owner** | Payments / application |
| **Runbook** | `payment:webhook-process`; provider dashboard; signature/config |
| **False positives** | UAT idle periods with injected fixtures |

### ALT-NOTIF-RETRY-DEAD — Notification retry / dead growth

| Field | Value |
|---|---|
| **Signal / query** | `notification_deliveries` in failed retryable or `dead` increasing |
| **Threshold** | Placeholder: dead +10/hour or retryable oldest >30 min |
| **Severity** | Ticket; Page if identity OTP channel failing broadly |
| **Owner** | Application / notifications |
| **Runbook** | `notification:deliver`; email adapter; [UAT_NOTIFICATION_OPERATIONS.md](../uat/UAT_NOTIFICATION_OPERATIONS.md) |
| **False positives** | Seeded UAT dead samples |

### ALT-DOC-STUCK — Stuck document scans

| Field | Value |
|---|---|
| **Signal / query** | Submissions in scanning beyond SLA; stuck-scan handler count |
| **Threshold** | Placeholder: any stuck >SLA (default 900s) for >2 cycles |
| **Severity** | Ticket → Page if upload path blocked for all learners |
| **Owner** | Application / credentials |
| **Runbook** | `document:scan`, `document:stuck-scan`; scanner adapter |
| **False positives** | Fake scanner paused in UAT |

### ALT-PAY-RECON-BACKLOG — Payment reconciliation backlog

| Field | Value |
|---|---|
| **Signal / query** | Payments in `reconciliation_pending` older than threshold |
| **Threshold** | Placeholder: oldest >30 min or count >20 |
| **Severity** | Page near payment-critical windows |
| **Owner** | Finance ops + engineering |
| **Runbook** | `payment:reconcile`; finance UI retry; provider status |
| **False positives** | Seeded `UAT-AWAIT-001` left idle |

### ALT-LOCK-STARVE — Scheduler lock starvation

| Field | Value |
|---|---|
| **Signal / query** | Repeated “Could not acquire … lock” on cleanup jobs |
| **Threshold** | Placeholder: >3 consecutive scheduled failures |
| **Severity** | Ticket |
| **Owner** | Platform |
| **Runbook** | Stuck process holding lock; TTL 120s; duplicate overlapping runners |
| **False positives** | Overlapping cron during long GC — investigate if persistent |

### ALT-WORKER-DOWN — Worker not running

| Field | Value |
|---|---|
| **Signal / query** | Heartbeat/last-success timestamp per job; systemd/Supervisor inactive |
| **Threshold** | No successful run in 2× cadence |
| **Severity** | Page for payment/notification/outbox; Ticket for cleanup |
| **Owner** | Platform |
| **Runbook** | [PROCESS_SUPERVISION_EXAMPLES.md](./PROCESS_SUPERVISION_EXAMPLES.md) |
| **False positives** | Intentional UAT pause |

### ALT-DISK-LOG — Disk / log growth

| Field | Value |
|---|---|
| **Signal / query** | Filesystem use on log and storage volumes |
| **Threshold** | Placeholder: >80% warning; >90% critical |
| **Severity** | Ticket → Page at critical |
| **Owner** | Platform |
| **Runbook** | [LOGGING.md](./LOGGING.md) rotation; local mail/document storage growth in UAT |
| **False positives** | One-off artifact dumps during UAT |

### ALT-BACKUP-FAIL — Backup failure

| Field | Value |
|---|---|
| **Signal / query** | Backup job non-zero exit; missing artifact; checksum mismatch |
| **Threshold** | Any failure |
| **Severity** | Page (pilot/production); Ticket (UAT) |
| **Owner** | Platform / DBA |
| **Runbook** | [BACKUP_RESTORE_RUNBOOK.md](./BACKUP_RESTORE_RUNBOOK.md) |
| **False positives** | Permission errors on new hosts |

### ALT-RESTORE-REHEARSAL-FAIL — Restore rehearsal failure

| Field | Value |
|---|---|
| **Signal / query** | `bin/restore-rehearsal.sh` (or equiv) non-zero; smoke after restore fails |
| **Threshold** | Any failure on scheduled rehearsal |
| **Severity** | Ticket (UAT); Page if production DR rehearsal |
| **Owner** | Platform |
| **Runbook** | [BACKUP_RESTORE_RUNBOOK.md](./BACKUP_RESTORE_RUNBOOK.md) |
| **False positives** | Target test DB name collision |

---

## Implementation honesty

| Present in application today | Not assumed |
|---|---|
| `/health/live`, `/health/ready` | Datadog/CloudWatch/PagerDuty integration |
| Worker logs + exit codes | Pre-built backlog SQL exporters |
| UAT backup/rehearsal scripts (RC-01) | Production PITR / multi-AZ DR |
