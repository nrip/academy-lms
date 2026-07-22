# WP-03 — Application Submission and Credential Documents

**Branch:** `slice/wp03-application-documents-submit`  
**Roadmap ID:** WP-03 (branch alias in roadmap: `slice/wp03-application-documents`)  
**Authority:** VERTICAL_SLICE_ROADMAP WP-03, STATE_MACHINE_ADDENDUM / SM-DOC-1, SRS §7.2/§8/§18.1, Architecture §11, Screen Inventory A-04/A-05, VS-WP-01-SOD.

## Scope

| In | Out |
|---|---|
| Complete `ApplicationStateMachine` matrix (unit) | Reviewer UI / VerificationAuditLog (WP-04) |
| Learner submit: Draft → Submitted → Under Review (Mode A, docs complete) | Payment / checkout (WP-05) |
| Document upload, confirm, replace, signed download | Admission / Enrolment (WP-06) |
| Complete `DocumentSubmissionStateMachine` (unit); scan gate to Under Review | Seat reservation at submit |
| Real Finance SoD (repo / signed URL / HTTP) | Course-specific invented questions |

## Application states owned here

**Eleven statuses** (REQ-APP-1 / WP-02 CHECK / `ApplicationStatus`):  
`draft`, `submitted`, `documents_incomplete`, `under_review`, `resubmission_requested`, `payment_pending`, `awaiting_verification`, `admitted`, `rejected`, `withdrawn`, `expired`.

**Learner HTTP path (Mode A):** `draft → submitted` then same transaction system edge `submitted → under_review` when all mandatory current documents are `status=under_review` and `scan_status=clean`.

**SM also encodes** all SRS §18.1 pairs for unit coverage. Reviewer/payment edges are callable only from services that WP-04+ will own; WP-03 does not expose them over HTTP.

**Doc note (non-blocking):** AGENTS/SRS transition text mentions `Payment Pending → Cancelled`, but REQ-APP-1 and the schema define 11 states without `cancelled`. Omitted from the machine until product reconciles.

## Document / scan model (addendum)

**Business `status`:** Not Uploaded (conceptual), Uploaded, Under Review, Approved, Rejected, Resubmission Requested, Expired, Superseded, Failed Security Scan.

**`scan_status`:** `not_applicable` | `pending` | `clean` | `failed`  
(Outstanding scan: `Uploaded` + `pending`. Stuck = `pending` beyond SLA — not a separate enum value.)

**Current row:** `current_marker = 1` unique per `(application_id, requirement_id)`; history `NULL`.

## Schema additions

**applications:** `application_number` (immutable public id), `state_version`, `declaration_accepted_version`, `declaration_accepted_at`. No full profile snapshot (profile remains source of truth; completeness re-checked at submit).

**document_submissions:** metadata + object key + business/scan status + `current_marker` + `row_version` + supersession timestamps. No content bytes in MySQL.

**document_upload_authorizations:** short-lived server-issued object keys for confirm.

## Storage / scanner

- `ObjectStorage` interface; `LocalObjectStorage` for local/testing/ci only; `S3ObjectStorage` when AWS config present; fail-closed `UnconfiguredObjectStorage` in production-like envs without config.
- `MalwareScanner` interface; deterministic `FakeMalwareScanner` only when `config.app.env` ∈ {local, testing, ci} **and** explicit enable flag; production-like without real scanner → fail closed / stuck-scan ops path.
- No storage/scanner/network I/O inside DB transactions.

## Submit preconditions

Account active; email + mobile verified; declaration version accepted; explicit profile required fields complete (same allow-list as profile completeness calculator — not percentage alone); every mandatory CourseVersion requirement has one current row with `scan_status=clean` and business status in {Uploaded→promoted Under Review, Under Review, Approved}; no pending/failed/stuck required docs. Optional requirements do not block when absent. Soft eligibility display rules are not re-invented as hard submit blockers beyond existing batch/version validity.

## Permissions added

`application.edit_own`, `application.submit_own`, `document.upload_own`, `document.view_own`, `document.replace_own` — granted to `applicant`. Existing `document.metadata.view` / `document.signed_url.generate` remain reviewer-only (Finance never).

## Demo

Local/testing path: local storage + fake scanner (filename/marker driven clean|failed) + WP-02 demo requirements → Draft → Submitted/Under Review. Impossible when `env` is staging/production.
