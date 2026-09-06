# Workers and Schedules — Academy LMS (RC-01)

**CLI entry:** `php bin/jobs.php <command>`  
**Locking:** `PdoSchedulerLock` for cleanup-style jobs; claim/lease fencing for queue workers.  
**Policy:** Do **not** schedule sub-minute cadences unless infrastructure and job design explicitly support it. Defaults below assume cron or systemd timers at **≥ 1 minute**.

Supervision examples (non-binding): [PROCESS_SUPERVISION_EXAMPLES.md](./PROCESS_SUPERVISION_EXAMPLES.md).  
Alerts: [ALERT_CATALOGUE.md](./ALERT_CATALOGUE.md).

---

## Environment notes

| Item | Guidance |
|---|---|
| `APP_ENV` | `local` / `testing` / `ci` / `uat` / `staging` / `production` |
| Fake adapters | Allowed in local/testing/ci/uat with explicit flags; **staging/production fail closed** |
| UAT seed/reset | Ops-only commands — **never** put on a schedule |

---

## Ops-only commands (not scheduled)

| Command | Purpose | Safety |
|---|---|---|
| `php bin/jobs.php uat:seed` | Deterministic UAT personas + scenario markers | Gated to `local\|testing\|ci\|uat` |
| `php bin/jobs.php uat:reset --confirm` | Remove UAT/demo data; preserve schema | Requires `--confirm`; refuses staging/production |
| `php bin/setup.php [--seed-uat]` | Clean-install verification / bootstrap | Must not silently wipe populated DBs |

Password for seed: `UAT_SEED_PASSWORD` or default documented in [UAT_ACCOUNTS.md](../uat/UAT_ACCOUNTS.md).

---

## Shared defaults (configurable)

| Setting | Env / config | Default |
|---|---|---|
| Outbox / worker lease | `OUTBOX_LEASE_SECONDS` | 60s |
| Outbox max attempts | `OUTBOX_MAX_ATTEMPTS` | 10 |
| Backoff base / cap | `OUTBOX_BACKOFF_BASE_SECONDS` / `OUTBOX_BACKOFF_CAP_SECONDS` | 5s / 3600s |
| Document scan lease | `DOCUMENTS_SCAN_LEASE_SECONDS` | 60s |
| Stuck-scan SLA | `DOCUMENTS_STUCK_SCAN_SLA_SECONDS` | 900s |
| Stuck-scan max attempts | `DOCUMENTS_STUCK_SCAN_MAX_ATTEMPTS` | 5 |
| Scheduler lock TTL (cleanup jobs) | hard-coded in `bin/jobs.php` acquire | 120s |
| Default claim batch | worker `limit` argument | 10 |

---

## Job catalogue

### `session:cleanup`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php session:cleanup` |
| **Purpose** | Delete expired sessions |
| **Recommended cadence** | Every 5–15 minutes |
| **Concurrency** | **1** (scheduler lock `session_cleanup`) |
| **Lease / lock** | Lock TTL 120s |
| **Timeout** | Keep under lock TTL; typically seconds |
| **Retry** | Next scheduled run |
| **Failure alert** | Repeated lock failure or non-zero exit — see ALERT_CATALOGUE |
| **Idempotency** | Delete-by-expiry; safe to re-run |
| **Shutdown** | Finish current delete batch; lock released in `finally` |

### `rate-limit:cleanup`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php rate-limit:cleanup` |
| **Purpose** | Delete expired rate-limit rows |
| **Recommended cadence** | Every 5–15 minutes |
| **Concurrency** | **1** (lock `rate_limit_cleanup`) |
| **Lease / lock** | 120s |
| **Idempotency** | Safe re-run |
| **Failure alert** | Non-zero exit / lock starvation |

### `outbox:relay`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php outbox:relay` |
| **Purpose** | Claim pending outbox messages and publish to configured transport |
| **Recommended cadence** | Every 1 minute |
| **Concurrency** | 1–2 processes; claim uses lease fencing (`lease_owner` / token) |
| **Lease** | `OUTBOX_LEASE_SECONDS` (default 60) |
| **Timeout** | Bound per-message publish; do not exceed lease without heartbeat design |
| **Retry** | Attempt count + backoff; dead after max attempts |
| **Idempotency** | Outbox `idempotency_key`; transport publish should be idempotent per key |
| **Notes** | If transport unconfigured, command exits 0 with skip message — **production-like envs must configure transport** |
| **Failure alert** | Backlog growth; repeated dead marks |

### `notification:deliver`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php notification:deliver` |
| **Purpose** | Run identity notification worker, then transactional notification worker |
| **Recommended cadence** | Every 1 minute |
| **Concurrency** | 1–2; delivery rows use lease tokens |
| **Lease** | Outbox lease seconds (default 60) |
| **Retry** | Retryable provider failures reschedule; permanent → dead |
| **Idempotency** | `UNIQUE (outbox_message_id, channel, template_key)` on deliveries; send keys include attempt fencing |
| **Failure alert** | Retryable queue growth; dead-letter growth |
| **Shutdown** | In-flight send may complete; DB finalise uses lease fencing |

### `token-confirmation:cleanup`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php token-confirmation:cleanup` |
| **Purpose** | Purge expired/consumed confirmation contexts past retention |
| **Recommended cadence** | Hourly |
| **Concurrency** | **1** (lock `token_confirmation_cleanup`) |
| **Lease / lock** | 120s |
| **Batch** | Config `TOKEN_CONFIRMATION_CLEANUP_BATCH_SIZE` (default 1000) |
| **Idempotency** | Safe re-run |

### `document:scan`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php document:scan` |
| **Purpose** | Claim submissions pending malware scan; apply scanner outcome |
| **Recommended cadence** | Every 1 minute |
| **Concurrency** | 1–2; submission scan lease |
| **Lease** | `DOCUMENTS_SCAN_LEASE_SECONDS` (default 60) |
| **Idempotency** | Claim + state checks; re-scan guarded by status |
| **Failure alert** | Pending scan backlog; scanner errors |
| **Notes** | UAT may use fake scanner with explicit flag; staging/production require real scanner pack |

### `document:stuck-scan`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php document:stuck-scan` |
| **Purpose** | Watchdog for scans exceeding SLA; reset/requeue per stuck-scan policy |
| **Recommended cadence** | Every 5 minutes |
| **Concurrency** | **1** (lock `document_stuck_scan`) |
| **Lease / lock** | 120s |
| **Policy** | SLA default 900s; max attempts default 5 |
| **Idempotency** | Safe periodic run |
| **Failure alert** | Rising stuck count / max-attempt exhaustion |

### `payment:webhook-process`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php payment:webhook-process` |
| **Purpose** | Process durable webhook receipts (signature already verified at HTTP edge) |
| **Recommended cadence** | Every 1 minute |
| **Concurrency** | 1–2; event lease fencing |
| **Lease** | Outbox/payment lease seconds (default 60) |
| **Max attempts** | `OUTBOX_MAX_ATTEMPTS` (default 10) |
| **Idempotency** | Unique gateway event key; handlers check payment/application state before transition |
| **Failure alert** | Webhook backlog; repeated processing failures |
| **Shutdown** | Lost lease → another worker may retry |

### `payment:reconcile`

| Field | Value |
|---|---|
| **Command** | `php bin/jobs.php payment:reconcile` |
| **Purpose** | Reconcile stale/pending reconciliation payments against provider |
| **Recommended cadence** | Every 5 minutes |
| **Concurrency** | 1–2; payment lease fencing; finance UI uses distinct worker id prefix |
| **Lease** | Default 60s |
| **Idempotency** | State machine + lease; no double Admit/Enrolment |
| **Failure alert** | Reconciliation backlog |

---

## Suggested UAT timer layout (example)

Not production-binding. Adjust after measuring backlog.

| Minute pattern | Jobs |
|---|---|
| `* * * * *` | `outbox:relay`, `notification:deliver`, `document:scan`, `payment:webhook-process` |
| `*/5 * * * *` | `session:cleanup`, `rate-limit:cleanup`, `document:stuck-scan`, `payment:reconcile` |
| `0 * * * *` | `token-confirmation:cleanup` |

Overlapping cron is acceptable **only** because locks/leases prevent double work; still prefer single runners per host role when possible.

---

## Health probes (related)

| Probe | Route | Role |
|---|---|---|
| Liveness | `GET /health/live` (alias `GET /health`) | Process up; no dependency checks |
| Readiness | `GET /health/ready` | DB, writable paths, adapter/key policy; optional build metadata |

---

## What is implemented vs example

| Implemented in repo | Example / environment-specific |
|---|---|
| `bin/jobs.php` commands above | Exact host paths, PHP binary, log shipper |
| PDO scheduler locks + row leases | systemd/Supervisor/cron unit files |
| Worker stdout summaries | Central metrics backend |
| UAT seed/reset gates | Production AWS/SQS transport wiring |
