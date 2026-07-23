# Production readiness register — Vertical Slice (post WP-07)

Unresolved items required before claiming production readiness. **None of these are implemented as production-complete in WP-07.**

| ID | Gap | Notes |
|---|---|---|
| PR-EMAIL | Production email provider (WP01-B) | SES recommended; Pending. Slice uses provider-neutral port + fail-closed / local / recording adapters. |
| PR-SMS | Production SMS + DLT pack (WP01-C) | Preferred/Pending. No transactional SMS in WP-07. |
| PR-HOST | Final AWS hosting / DR approval | Architecture assumption only. |
| PR-S3 | Production object storage + malware scanner | Slice uses configured adapters; production pack TBD. |
| PR-RZP | Razorpay webhook secret / credential deployment | Must be environment-injected; never committed. |
| PR-ALERT | Operational alerting / monitoring | Outbox dead-letter, delivery dead, webhook failures. |
| PR-CRON | Queue / cron scheduling | `bin/jobs.php` commands must be scheduled (outbox, notification, webhook, reconcile, scan). |
| PR-LOAD | Load testing | MySQL sessions, rate limits, outbox throughput. |
| PR-BACKUP | Backup / restore testing | — |
| PR-SEC | Security review / penetration testing | Mandatory for auth, payments, documents. |
| PR-RETENTION | Data retention / privacy review | Notification delivery retention, audit retention. |
| PR-CANCEL | OD-APP-CANCELLED | Open product decision. |
| PR-REFUND | Refund / dispute / manual reconciliation policy | Beyond Mode A reconcile retry. |
| PR-PLAYER | Course player / assessments / certificates | Explicitly out of vertical-slice scope (VS-SCOPE-1). |
| PR-TMPL | SA-04 editable NotificationTemplate CMS | REQ-NOTIF-2; WP-07 uses code-owned versioned templates. |
| PR-INAPP | L-09 in-app notification centre | Out of WP-07; email transactional only. |

Do not treat WP-07 merge as closing any row above.
