# Production readiness register — Vertical Slice (post WP-07 / RC-01 triage)

Unresolved items required before claiming production readiness. **None of these are implemented as production-complete in WP-07 or RC-01.**

**Classification key (RC-01 AA):**

| Classification | Meaning |
|---|---|
| **required before UAT** | Must be satisfied (or explicitly waived with adapter policy) before meaningful Mode A UAT |
| **required before pilot** | Required before limited real-learner pilot |
| **required before production** | Required before general production cutover |
| **future product scope** | Not blocking UAT; product backlog beyond vertical-slice Mode A |

RC-01 documents UAT/ops hardening only. Do **not** treat documentation or UAT fake adapters as closing a row.

| ID | Gap | Classification | Notes |
|---|---|---|---|
| PR-EMAIL | Production email provider (WP01-B) | required before pilot | SES recommended; Pending. Slice/UAT may use local_file/recording with explicit config. Staging/production fail closed without real provider. |
| PR-SMS | Production SMS + DLT pack (WP01-C) | required before production | Preferred/Pending. No transactional SMS in WP-07. Mobile verification UAT may be N/A when SMS unavailable. |
| PR-HOST | Final AWS hosting / DR approval | required before production | Architecture assumption only — not implemented as a hosting pack in-repo. |
| PR-S3 | Production object storage | required before pilot | Slice/UAT may use local disk driver with explicit config. Production needs private S3 (+ IAM) pack. |
| PR-MALWARE | Production malware scanner | required before pilot | Distinct from storage. UAT may use fake scanner with explicit flag; staging/production fail closed. |
| PR-RZP | Razorpay webhook secret / credential deployment | required before pilot | Must be environment-injected; never committed. UAT may use fake gateway with explicit flag; real Razorpay UAT still needs injected secrets. |
| PR-ALERT | Operational alerting / monitoring | required before pilot | Catalogue documented in RC-01; **integration not claimed**. Outbox DLQ, delivery dead, webhook failures. |
| PR-CRON | Queue / cron scheduling / process supervision | required before UAT | `bin/jobs.php` must be runnable on a cadence for async UAT paths. Examples only in PROCESS_SUPERVISION_EXAMPLES — not a hosted scheduler. |
| PR-LOAD | Load testing | required before production | MySQL sessions, rate limits, outbox throughput. |
| PR-BACKUP | Backup / restore testing | required before pilot | RC-01 adds **UAT** mysqldump rehearsal only — does not close production DR. |
| PR-SEC | Security review / penetration testing | required before production | Mandatory for auth, payments, documents. |
| PR-RETENTION | Data retention / privacy review | required before production | Notification delivery retention, audit retention; logging placeholders in LOGGING.md. |
| PR-CANCEL | OD-APP-CANCELLED | future product scope | Open product decision — not silently closed. |
| PR-REFUND | Refund / dispute / manual reconciliation policy | future product scope | Beyond Mode A reconcile retry. |
| PR-PLAYER | Course player | future product scope | Explicitly out of vertical-slice scope (VS-SCOPE-1). |
| PR-ASSESS | Assessments | future product scope | Out of vertical-slice scope. |
| PR-CERT | Certificates | future product scope | Out of vertical-slice scope. |
| PR-TMPL | SA-04 editable NotificationTemplate CMS | future product scope | REQ-NOTIF-2; WP-07 uses code-owned versioned templates. |
| PR-INAPP | L-09 in-app notification centre | future product scope | Out of WP-07; email transactional only. |

Do not treat WP-07 merge or RC-01 UAT hardening as closing any row above.
