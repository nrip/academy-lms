# UAT Sign-off Template — Academy LMS (RC-01)

**Package:** RC-01 — UAT Release and Deployment Hardening  
**UAT cycle:** _  
**Environment URL:** _  
**Commit / tag:** _  
**Seed revision / notes:** _

---

## Scope confirmation

| Item | In / Out | Confirmed |
|---|---|---|
| Mode A learner → admit → dashboard | In | ☐ |
| Reviewer document/application decisions | In | ☐ |
| Finance payment visibility + reconcile (no mark-paid) | In | ☐ |
| Transactional notification ops | In | ☐ |
| Security negative pack | In | ☐ |
| Course player / assessments / certificates | Out | ☐ |
| Refund automation / production SES/SMS/AWS packs | Out | ☐ |

---

## Execution checklist

| Suite | Document | Executed by | Date | Pass? |
|---|---|---|---|---|
| Learner | [UAT_LEARNER_JOURNEY.md](./UAT_LEARNER_JOURNEY.md) | | | ☐ |
| Reviewer | [UAT_REVIEWER_JOURNEY.md](./UAT_REVIEWER_JOURNEY.md) | | | ☐ |
| Finance | [UAT_FINANCE_JOURNEY.md](./UAT_FINANCE_JOURNEY.md) | | | ☐ |
| Notifications | [UAT_NOTIFICATION_OPERATIONS.md](./UAT_NOTIFICATION_OPERATIONS.md) | | | ☐ |
| Security negative | [UAT_SECURITY_NEGATIVE_TESTS.md](./UAT_SECURITY_NEGATIVE_TESTS.md) | | | ☐ |

---

## Operational gates

| Gate | Evidence | Pass? |
|---|---|---|
| `GET /health/live` → 200 | | ☐ |
| `GET /health/ready` → 200 | | ☐ |
| Workers runnable (`bin/jobs.php …`) | | ☐ |
| UAT seed/reset documented and gated | | ☐ |
| Backup/restore rehearsal (UAT) | [BACKUP_RESTORE_RUNBOOK.md](../operations/BACKUP_RESTORE_RUNBOOK.md) | ☐ |
| Release checklist | [RC01_RELEASE_CHECKLIST.md](../releases/RC01_RELEASE_CHECKLIST.md) | ☐ |

---

## Defect disposition

| Metric | Count |
|---|---:|
| Open Blocker | |
| Open Critical | |
| Open Major (with PO disposition) | |
| Deferred Minor/Cosmetic | |

Defect log link/path: _

**Exit rule met?** ☐ Yes — no open Blocker/Critical; Majors dispositioned  
**Exit rule met?** ☐ No — release blocked

---

## Known limitations / readiness items carried forward

List IDs from [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) that remain open (do not mark closed here):

-
-
-

---

## Approvals

| Role | Name | Date | Signature / ack |
|---|---|---|---|
| UAT Lead | | | |
| Engineering | | | |
| Product Owner | | | |
| Security (if Critical/security defects waived) | | | |

---

## Decision

☐ **Approved for UAT exit / RC-01 handoff**  
☐ **Approved with documented deferrals**  
☐ **Rejected — return to engineering**

Comments:
