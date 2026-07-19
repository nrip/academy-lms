# State Machine Addendum

**Document status:** Authoritative for missing transition detail until incorporated into a future formal SRS revision.  
**Recorded in:** [`DECISION_LOG.md`](./DECISION_LOG.md) (`SM-ADDENDUM-1`).  
**Does not replace:** SRS v6.0 §18 Application and Enrolment matrices, or AGENTS.md / Technical Architecture Payment and Application rules.  
**SRS v6.0 must not be edited in place** for these additions.

---

## 1. Purpose

SRS v6.0 defines DocumentSubmission **business statuses** (REQ-DOC-2) and the upload/scan/review behaviour (REQ-DOC-3/4, Architecture §11) but does not provide a §18-style transition table for `DocumentSubmission`. AGENTS.md requires a `DocumentSubmissionStateMachine`.

This addendum supplies that matrix and separates **business status** from **malware scan status**.

---

## 2. Two separate status dimensions

Document business status and malware scan status are **separate**.

### 2.1 Business status (`status`) — REQ-DOC-2 only

| Status | Meaning (summary) |
| --- | --- |
| Not Uploaded | Requirement exists; no current submission row, or no acceptable current file yet |
| Uploaded | Object received for this submission row; business lifecycle has accepted the upload |
| Under Review | Eligible for reviewer queue (see §5 queue-entry condition) |
| Approved | Reviewer approved this submission version |
| Rejected | Reviewer rejected this submission version |
| Resubmission Requested | Reviewer requested a replacement; learner must upload a **new** submission row |
| Expired | This submission version expired under policy |
| Superseded | Replaced by a newer submission for the same requirement; **immutable** historical row |
| Failed Security Scan | Business outcome after scan failure / malware detection; quarantined; never shown to reviewers as reviewable content |

Do **not** add `Scan Pending` as a REQ-DOC-2 business status.

### 2.2 Malware scan status (`scan_status`) — separate field

Aligned with Architecture §11 (`scan_status` distinct from lifecycle `status`):

| `scan_status` | Meaning |
| --- | --- |
| `not_applicable` | No object yet (e.g. conceptual Not Uploaded / no row) |
| `pending` | Object stored; scan not completed |
| `clean` | Scan completed; no malware / policy fail |
| `failed` | Scan completed; malware or scan-policy failure |

While scan is outstanding: **`status = Uploaded`** and **`scan_status = pending`**.

---

## 3. DocumentSubmission transition matrix (business `status`)

**Status of this matrix:** Approved for implementation planning with the modelling rules in §4–§5 (Decision Log `SM-DOC-1`).

| From | Allowed to | Trigger / notes |
| --- | --- | --- |
| Not Uploaded | Uploaded | First submission row created after successful upload acceptance |
| Uploaded | Under Review | `scan_status` becomes `clean` **and** queue-entry condition (§5) is met |
| Uploaded | Failed Security Scan | `scan_status` becomes `failed` |
| Under Review | Approved | Reviewer approve |
| Under Review | Rejected | Reviewer reject with required reason code |
| Under Review | Resubmission Requested | Reviewer requests resubmission |
| Resubmission Requested | Expired | Resubmission window lapses (timing per course/ops config) |
| Resubmission Requested | Superseded | New submission row created for the same requirement (see §4) |
| Failed Security Scan | Superseded | Learner retries with a **new** submission row (see §4.2) |
| Approved | Superseded | Newer submission supersedes (e.g. mandatory re-verification); prior row immutable |
| Approved | Expired | Credential/document validity expiry rules |
| Rejected | Superseded | New submission row created after reject when learner re-uploads |
| Superseded | — | Terminal for that row |
| Expired | — | Terminal for that row (no Mode A reopen policy) |
| Failed Security Scan | — | Terminal for that row once superseded by retry, or remains Failed until retry |

**There is no transition** `Resubmission Requested → Uploaded` or `Failed Security Scan → Uploaded` on the **same** row. Retries always create a **new** row.

### 3.1 Disallowed examples (non-exhaustive)

- Any path to Under Review / Approved without `scan_status = clean`
- Failed Security Scan → Under Review / Approved
- Not Uploaded → Under Review / Approved
- Mutating a Superseded, Approved, Rejected, or Expired row’s prior review outcome
- Overwriting file bytes or review fields on a previously reviewed row
- Any transition outside `DocumentSubmissionStateMachine`
- Repository `updateStatus()` API

---

## 4. Resubmission and versioning model (approved)

### 4.1 New row; prior row immutable

- Resubmission **creates a new `DocumentSubmission` row**.
- The prior row remains **immutable** and is marked **`Superseded`** when the new row is accepted as the replacement attempt (at upload acceptance for that requirement).
- Do **not** overwrite previously reviewed submissions (including Approved, Rejected, Resubmission Requested content, verification notes, or file object keys).

### 4.2 Retry after scan failure or malware detection

1. Current row transitions **Uploaded → Failed Security Scan** (and `scan_status = failed`); object remains quarantined.  
2. Learner initiates retry → **new** DocumentSubmission row with `status = Uploaded`, `scan_status = pending`.  
3. Prior failed row → **Superseded** (immutable).  
4. New row follows scan → Under Review or Failed Security Scan again.

Same pattern applies when status is **Resubmission Requested**: learner upload creates a new row; the Resubmission Requested row becomes **Superseded**.

### 4.3 Current submission per requirement

For each `(application_id, requirement_id)`:

- The **current submission** is the single non-terminal row that is not `Superseded` and not `Expired`, preferring the latest by `created_at` / `document_id` if multiple non-superseded rows would otherwise exist (implementation must enforce at most one “open” current row per requirement).
- Open/current statuses: `Uploaded`, `Under Review`, `Resubmission Requested`, `Failed Security Scan` (awaiting learner retry), or `Approved` (satisfied).
- Historical rows (`Superseded`, and prior `Rejected`/`Failed Security Scan` after supersession) remain queryable for audit.

Application “documents complete / under review” logic uses **current** submissions only.

### 4.4 Reviewer access to prior versions

- Reviewers with permission **and** object scope may view **prior versions** (Superseded / historically Rejected / historically Failed Security Scan metadata) for the same requirement, via explicit history UI (R-03 / detail history).
- **Failed Security Scan** file content remains quarantined: reviewers must **not** receive signed URLs to malware-positive objects.
- Prior **clean** versions may be viewed via short-lived signed URLs under the same SoD rules (Finance never).
- VerificationAuditLog remains append-only per document version.

---

## 5. Exact queue-entry condition

A DocumentSubmission enters the **reviewer queue** (R-01) if and only if **all** of the following are true:

1. `status = Under Review`
2. `scan_status = clean`
3. The row is the **current** submission for its `(application_id, requirement_id)` (not `Superseded`)
4. The acting reviewer satisfies **permission** and **object scope** for the related Application’s course / course version / batch

Transition **Uploaded → Under Review** may occur only when `scan_status` becomes `clean`. Queue listing filters on the conditions above; UI filters are non-authoritative.

---

## 6. Application Draft construction (clarification; not a transition)

1. Creating an Application with status **Draft** is an **entity factory / constructor** operation at persistence time.  
2. It is **not** a transition from a prior Application status.  
3. After persistence, **every** status change uses `ApplicationStateMachine` only.  
4. Repositories must not expose general `updateStatus()`.  
5. WP-02 must not introduce temporary raw status writers.

Application transition pairs remain those in SRS §18.1 / AGENTS.md §6.1 (full matrix tested when the SM is introduced in WP-03).

---

## 7. Payment and Enrolment

- Payment transitions: AGENTS.md §6.3 / Architecture — full matrix when introduced in WP-05.  
- Enrolment transitions: SRS §18.2 — full matrix when introduced in WP-06.  
- Payment attempt rule: Decision Log **PAY-ATTEMPT-1**.

---

## 8. Approval record

| Item | Status |
| --- | --- |
| Business status vs scan status separation (§2) | Approved |
| New-row resubmission; prior row Superseded and immutable (§4) | Approved |
| Current submission definition (§4.3) | Approved |
| Retry after scan failure (§4.2) | Approved |
| Reviewer prior-version access rules (§4.4) | Approved |
| Queue-entry condition (§5) | Approved |
| Transition matrix (§3) | Approved for Mode A slice implementation |
