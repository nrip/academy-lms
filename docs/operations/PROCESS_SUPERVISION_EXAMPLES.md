# Process Supervision Examples — Academy LMS (RC-01)

**Authority:** RC-01 section R  

> **Non-binding examples.** These snippets illustrate how an environment *might* supervise `php bin/jobs.php` workers. They are **not** production standards, do not embed secrets, and must be reviewed for each host (user, paths, PHP binary, logging, restart policy).

Related: [WORKERS_AND_SCHEDULES.md](./WORKERS_AND_SCHEDULES.md).

---

## Conventions used in examples

| Item | Example value | Notes |
|---|---|---|
| App root | `/var/www/academy-lms` | Change per host |
| PHP CLI | `/usr/bin/php8.4` | Must be PHP 8.4 |
| User | `academy` | Least privilege; not root |
| Logs | `/var/log/academy-lms/` | Outside web root; rotate separately |
| Secrets | Environment / systemd `EnvironmentFile=` | Never hard-code passwords |

Overlapping timers are mitigated by PDO scheduler locks and row leases — still prefer one primary runner per job class per environment.

---

## systemd (examples)

### Timer + oneshot for a minute job

`/etc/systemd/system/academy-outbox-relay.service`:

```ini
[Unit]
Description=Academy LMS outbox relay (EXAMPLE)
After=network.target mysql.service

[Service]
Type=oneshot
User=academy
WorkingDirectory=/var/www/academy-lms
EnvironmentFile=-/etc/academy-lms/uat.env
ExecStart=/usr/bin/php8.4 bin/jobs.php outbox:relay
Nice=10
```

`/etc/systemd/system/academy-outbox-relay.timer`:

```ini
[Unit]
Description=Academy LMS outbox relay timer (EXAMPLE)

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
Unit=academy-outbox-relay.service

[Install]
WantedBy=timers.target
```

Enable (example):

```bash
sudo systemctl enable --now academy-outbox-relay.timer
```

Repeat the pattern for `notification:deliver`, `document:scan`, `payment:webhook-process` (1-minute), and slower jobs at `*/5` or hourly calendars. **Do not** create timers for `uat:seed` / `uat:reset`.

---

## Supervisor (example)

`/etc/supervisor/conf.d/academy-workers.ini`:

```ini
; EXAMPLE ONLY — prefer systemd timers for cron-like jobs.
; Long-running loop wrappers are NOT provided by bin/jobs.php today;
; each invoke is a finite batch. Use cron/systemd timers unless you add a loop wrapper.

[program:academy-notification-deliver-EXAMPLE]
command=/usr/bin/php8.4 /var/www/academy-lms/bin/jobs.php notification:deliver
directory=/var/www/academy-lms
user=academy
autostart=false
autorestart=false
redirect_stderr=true
stdout_logfile=/var/log/academy-lms/notification-deliver.log
```

Because jobs are batch-oriented, Supervisor `autorestart=true` on a oneshot will tight-loop — **avoid** unless you wrap with `sleep`. Prefer systemd timers or cron.

---

## cron (example)

`/etc/cron.d/academy-lms-uat` (example):

```cron
# EXAMPLE — paths/user must be customized. Shell env should load APP_ENV via wrapper.
MAILTO=""

*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php outbox:relay >> /var/log/academy-lms/outbox.log 2>&1
*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php notification:deliver >> /var/log/academy-lms/notification.log 2>&1
*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php document:scan >> /var/log/academy-lms/document-scan.log 2>&1
*/1 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php payment:webhook-process >> /var/log/academy-lms/webhook.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php payment:reconcile >> /var/log/academy-lms/reconcile.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php document:stuck-scan >> /var/log/academy-lms/stuck-scan.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php session:cleanup >> /var/log/academy-lms/session-cleanup.log 2>&1
*/5 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php rate-limit:cleanup >> /var/log/academy-lms/rate-limit-cleanup.log 2>&1
0 * * * * academy cd /var/www/academy-lms && /usr/bin/php8.4 bin/jobs.php token-confirmation:cleanup >> /var/log/academy-lms/token-cleanup.log 2>&1
```

Wrapper tip: a small `bin/run-job.sh` can `set -a; source /etc/academy-lms/uat.env; set +a` before exec — keep the env file mode `0600` and out of git.

---

## Restart behaviour

| Goal | Approach |
|---|---|
| Deploy | Stop timers → deploy → migrate → start timers ([UAT_DEPLOYMENT_RUNBOOK.md](./UAT_DEPLOYMENT_RUNBOOK.md)) |
| Stuck lock | Wait TTL (120s) or release only with understanding of `scheduler_locks` ownership |
| Crash mid-lease | Row leases expire; another worker claims |

---

## Explicitly out of scope here

- Kubernetes CronJob / ECS scheduled task YAML (add when hosting pack exists)
- Embedding Razorpay or DB passwords in unit files
- Scheduling `uat:seed` / `uat:reset`
