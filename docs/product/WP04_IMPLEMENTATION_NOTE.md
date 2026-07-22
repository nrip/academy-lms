# WP-04 — Reviewer Verification

**Branch:** `slice/wp04-reviewer-verification`  
**Roadmap ID:** WP-04 Reviewer Verification  
**Authority:** VERTICAL_SLICE_ROADMAP WP-04, STATE_MACHINE_ADDENDUM / SM-DOC-1, SRS REQ-REV-1/2 / §8.2 / §18.1, WP01-E, Screen Inventory R-01/R-02, HF Reviewer Verification Workflow.

## Scope

| In | Out |
|---|---|
| R-01 queue + R-02 detail | Payment records / Razorpay (WP-05) |
| WP01-E reviewer object scope | Admission / Enrolment (WP-06) |
| Application claim (one active) | Bulk admin assignment UI beyond claim/release |
| Document verify / reject / resubmission request | R-03 standalone audit viewer (history on R-02) |
| Application approve → **Payment Pending** (no Payment row) | Notifications delivery (WP-07) |
| Application reject; correction + learner resubmit | Course Admin builders |
| VerificationAuditLog (append-only) | |

## Application transitions owned here

Exact SM edges (no Application status named `approved`):

| From | To | Actor | Meaning |
|---|---|---|---|
| `under_review` | `payment_pending` | reviewer | Mode A approval — **no Payment created** |
| `under_review` | `rejected` | reviewer | Rejection + reason code |
| `under_review` | `resubmission_requested` | reviewer | Correction request |
| `resubmission_requested` | `under_review` | system | Learner correction resubmit complete |

`documents_incomplete` is not used for Mode A correction (SRS correction path is `resubmission_requested`).

## Document statuses (addendum)

Reviewer acts only on **current** rows with `status=under_review` and `scan_status=clean`:

- verify → `approved`
- reject → `rejected` + SRS reason code
- request replacement → `resubmission_requested` + reason code

Historical / superseded rows are read-only. Failed-scan objects never get signed URLs.

## Assignment models

1. **Object scope (WP01-E):** `reviewer_scope_assignments` — Course / CourseVersion / Batch; union of active; `include_future_versions` default 0; effective dates + revoke history. Permission **and** scope required.
2. **Queue claim:** `application_review_assignments` — at most one `active_marker=1` per Application; concurrent claim → one winner (`FOR UPDATE` + unique).

## Permissions

Reuse catalogue; add only missing action keys:

| Key | Use |
|---|---|
| `reviewer.queue.view` | R-01 |
| `reviewer.application.view` | R-02 |
| `reviewer.application.claim` | claim / release |
| `reviewer.document.review` | verify / reject / request resubmission |
| `reviewer.document.history` | history panel |
| `reviewer.application.approve` | → payment_pending |
| `reviewer.application.reject` | → rejected |
| `document.metadata.view` / `document.signed_url.generate` | reviewer download |
| `application.resubmit_corrections_own` | learner correction resubmit |

Finance never receives document keys. Role names never bypass checks.

## Reason codes (REQ-REV-1)

`blurry_illegible`, `wrong_document`, `incomplete`, `expired_registration`, `name_mismatch`, `qualification_ineligible`, `registration_number_not_visible`, `issuing_authority_not_identifiable`, `other`.

Learner-visible message: length-bounded, sanitized, stored on submission / correction row. Internal notes: VerificationAuditLog only (never general `audit_log` payload, never shown to learner).

## Queue filters (server-side)

`unassigned` | `assigned_to_me` | `under_review` | `resubmission_requested` | `ready_for_decision` | `recently_decided`  
Default sort: oldest `submitted_at` first. Rows: application_number, course, batch, submitted_at, status, assignment, SLA age band, document completeness summary — no email/mobile/PII dump.

## Approve preconditions

All mandatory current docs `approved` + clean; no current rejected / resubmission_requested / pending-scan; active claim by acting reviewer (or Super Admin with scope); application `under_review`; matching `state_version`.

## Contradictions checked — none blocking

- Prompt “Approved” Application state → resolved by SRS/roadmap: approval **outcome** sets Application to **Payment Pending**.
- Prompt “Documents Incomplete” for correction → SM uses **Resubmission Requested**.
- Tech Lead schema review for WP01-E still Pending in Decision Log — Product Owner model is Approved; implement that model (same posture as prior WPs).
