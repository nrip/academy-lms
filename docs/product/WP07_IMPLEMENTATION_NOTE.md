# WP-07 — Learner Dashboard and UAT Hardening

**Roadmap ID:** WP-07 Learner Dashboard and UAT Hardening  
**Branch:** `slice/wp07-dashboard-notifications-completion` (roadmap alias `slice/wp07-learner-dashboard-uat`)  
**Authority:** VERTICAL_SLICE_ROADMAP WP-07, VS-SCOPE-1, VS-NOTIF-1, SRS v6.1 §13.1 / §14 (Mode A subset), Screen Inventory L-01 (Scheduled/Active only).

## Scope

| In | Out |
|---|---|
| `GET /dashboard` (L-01 slice) — Applications, Payment, Enrolment, next actions | Course player / progress / resume / assessments / certificates |
| Learner status presenter (safe labels + next actions) | Waitlist, refunds, mark-paid, Course Admin builder |
| Transactional email from existing outbox domain events | Production SES/SMS/WhatsApp; SA-04 admin template editor |
| `notification_deliveries` + worker (lease/fencing) | In-app notification centre (L-09) |
| Ops list/detail/retry for deliveries | Marketing preferences / campaigns |
| Payment-result → dashboard handoff; Enrolment as admit SoT | ContentProgress / player access |
| Mode A E2E + focused regressions | Unrelated admissions/payment redesign |
| Permission-based post-login destination resolver | Role-name branching; restore `/smoke` as production landing |

## Decisions (no blockers)

1. **Templates:** Code-owned, versioned templates with variable allow-lists. Persisted delivery rows store `template_key` + `template_version`. **REQ-NOTIF-2 admin-editable DB templates (SA-04) remain a production-readiness gap** — not invented as DB CMS in this slice.
2. **Channels:** Email only via existing `EmailDeliveryPort` (recording / local_file / unavailable). Transactional SMS deferred until WP01-C; no WhatsApp.
3. **Event ownership:** Worker claims existing outbox types (`application.submitted`, correction/approve/reject, payment failed/success/recon, `application.admitted`, `enrolment.created`). No polling; no delivery I/O inside domain TXs. Relay excludes these types (same pattern as identity OTP).
4. **Idempotency:** `UNIQUE (outbox_message_id, channel, template_key)` on `notification_deliveries`. Duplicate outbox claim → one delivery row.
5. **Dashboard:** Read-only query model scoped by `auth.user_id`. Latest payment = highest `payment_id` per application. Enrolment row is authoritative for admission completion. No document/webhook/reviewer internals.
6. **Permissions:** `dashboard.view_own` (applicant); `enrolment.view_own` (existing); `notification.view` / `notification.retry` (super_admin, sensitive). No Finance document access. No role-name checks.
7. **Preferences:** Transactional Mode A emails are mandatory when email is configured; no marketing opt-out surface.
8. **Post-login:** `PostLoginDestinationResolver` (permission-based). Precedence: reviewer queue → finance reconcile/payments → notification ops → learner dashboard → profile → courses. Applied to already-authenticated `GET /login` and fresh `POST /login`. Allow-listed `return_to` only (no open redirect). Non-active accounts never receive privileged landings. Route middleware remains authoritative; `/smoke` is not restored as production landing.

## Contradictions / gaps recorded (not blockers)

| Item | Resolution in this PR |
|---|---|
| SRS REQ-DASH-LRN-1 full surface vs VS-SCOPE-1 | Slice: applications + payment + Scheduled/Active enrolment; no player/progress |
| SRS REQ-NOTIF-2 editable templates | Code-owned templates + delivery persistence; SA-04 deferred to readiness register |
| WP01-B production email Pending | Fail-closed `unavailable` outside local/testing/ci |

## Routes

- `GET /dashboard` — `dashboard.view_own`
- `GET /admin/notifications`, `GET /admin/notifications/{id}`, `POST /admin/notifications/{id}/retry`
- Existing application/payment routes linked from dashboard next actions

## Worker

`php bin/jobs.php notification:deliver` — identity OTP worker, then transactional delivery worker.

### Suite totals
| Gate | Result |
|---|---|
| Full PHPUnit | **1103 tests / 2972 assertions** |
| Migration `20260724000001` rollback → reapply | Pass |
| PHPStan | Pass (0 errors) |
| php-cs-fixer dry-run | Pass (0 files) |
| composer audit | No advisories |
| `git diff --check` | Pass |

## Out of scope / readiness
See `PRODUCTION_READINESS_REGISTER.md`. Not pushed.
