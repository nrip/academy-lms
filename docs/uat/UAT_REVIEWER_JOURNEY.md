# UAT Reviewer Journey — Academy LMS (RC-01)

**Authority:** RC-01 section K  
**Persona default:** `reviewer@uat.example.test`  
**Overview:** [UAT_OVERVIEW.md](./UAT_OVERVIEW.md) · **Accounts:** [UAT_ACCOUNTS.md](./UAT_ACCOUNTS.md)

Complete MFA enrolment for the reviewer persona before privileged cases. Fill Result / Defect ref / Severity during execution.

---

## UAT-R-01 — Reviewer login landing

| Field | Value |
|---|---|
| **Test ID** | UAT-R-01 |
| **Persona** | Reviewer |
| **Preconditions** | Seeded reviewer; MFA enrolment completed for privileged assurance |
| **Steps** | 1. Login at `/login`. 2. Observe post-login destination. |
| **Expected result** | Lands on reviewer queue (`/reviewer/applications`) when permissions + MFA assurance allow; not finance or learner dashboard. |
| **Evidence** | Landing URL; request ID. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-02 — Reviewer queue

| Field | Value |
|---|---|
| **Test ID** | UAT-R-02 |
| **Persona** | Reviewer |
| **Preconditions** | At least one in-scope application under review (`UAT-REVIEW-001` or live submit) |
| **Steps** | 1. GET `/reviewer/applications`. 2. Confirm queue columns and filters load. 3. Open an application detail. |
| **Expected result** | Only in-scope batch applications visible; no finance payment secrets; no cross-batch leakage outside assigned scope. |
| **Evidence** | Queue screenshot; application numbers listed. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-03 — Claim application

| Field | Value |
|---|---|
| **Test ID** | UAT-R-03 |
| **Persona** | Reviewer |
| **Preconditions** | Unclaimed application in queue |
| **Steps** | 1. Open application. 2. POST claim. 3. Refresh detail. |
| **Expected result** | Claim succeeds; application shows claimed by this reviewer; release available per UI. |
| **Evidence** | Claimed-by indicator; audit-safe confirmation. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-04 — Inspect allowed document data

| Field | Value |
|---|---|
| **Test ID** | UAT-R-04 |
| **Persona** | Reviewer |
| **Preconditions** | Claimed application with document submissions |
| **Steps** | 1. View document metadata on reviewer detail. 2. Open signed download for one submission. |
| **Expected result** | Reviewer sees allowed document metadata and can download via short-lived signed URL; no gateway secrets; object key not unnecessarily exposed in UI. |
| **Evidence** | Document statuses; download HTTP 200 once. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-05 — Approve document

| Field | Value |
|---|---|
| **Test ID** | UAT-R-05 |
| **Persona** | Reviewer |
| **Preconditions** | Clean document awaiting verification |
| **Steps** | 1. POST verify/approve document. 2. Confirm status update. |
| **Expected result** | DocumentSubmission transitions via state machine; invalid transition returns 409 without partial corruption. |
| **Evidence** | Before/after document status. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-06 — Reject document

| Field | Value |
|---|---|
| **Test ID** | UAT-R-06 |
| **Persona** | Reviewer |
| **Preconditions** | Document eligible for rejection |
| **Steps** | 1. POST reject with reason. 2. Confirm learner-visible correction path exists when required. |
| **Expected result** | Rejection recorded with reason; finance still cannot see document; audit written. |
| **Evidence** | Status + reason (non-sensitive). |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-07 — Request correction

| Field | Value |
|---|---|
| **Test ID** | UAT-R-07 |
| **Persona** | Reviewer |
| **Preconditions** | Claimed application eligible for correction request |
| **Steps** | 1. Request document resubmission and/or application-level correction. 2. Confirm application moves to Resubmission Requested. |
| **Expected result** | Valid SM transition; learner corrections UI becomes available; notifications queued when workers run. |
| **Evidence** | Application status; optional notification delivery after workers. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-08 — Application approval

| Field | Value |
|---|---|
| **Test ID** | UAT-R-08 |
| **Persona** | Reviewer |
| **Preconditions** | Mode A application with documents satisfied and eligible for payment pending |
| **Steps** | 1. POST approve. 2. Confirm Application → Payment Pending (Mode A). 3. Confirm **no Enrolment** created yet. |
| **Expected result** | Approval does not admit; payment path opens; enrolment absent until Admitted after payment. |
| **Evidence** | Status; enrolment absence check via learner dashboard/application. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-09 — Application rejection

| Field | Value |
|---|---|
| **Test ID** | UAT-R-09 |
| **Persona** | Reviewer |
| **Preconditions** | Application eligible to reject (use a dedicated fixture; do not burn happy-path app) |
| **Steps** | 1. POST reject with reason. 2. Confirm Rejected. 3. Confirm no Enrolment. |
| **Expected result** | Rejected terminal for Mode A path used; payment/enrolment invariants hold (no enrolment). |
| **Evidence** | Status; enrolment absence. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-10 — Concurrent claim behaviour

| Field | Value |
|---|---|
| **Test ID** | UAT-R-10 |
| **Persona** | Reviewer + Multi (`multi@uat.example.test`) or second reviewer |
| **Preconditions** | Two privileged reviewers with overlapping scope; one unclaimed application |
| **Steps** | 1. Both open the same application. 2. Both submit claim nearly simultaneously. |
| **Expected result** | Exactly one claim wins; loser receives conflict/denied response; no double-owner state. |
| **Evidence** | Both response statuses; final claimed-by. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-11 — Reviewer scope restrictions

| Field | Value |
|---|---|
| **Test ID** | UAT-R-11 |
| **Persona** | Reviewer |
| **Preconditions** | Application exists on a batch **outside** reviewer scope (create via admin/seed outside demo batch if available; otherwise N/A with note) |
| **Steps** | 1. Attempt queue visibility. 2. Attempt direct GET/claim URLs by id. |
| **Expected result** | Out-of-scope applications hidden and not claimable (403/404). |
| **Evidence** | Status codes; request IDs. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-12 — Signed download expiration

| Field | Value |
|---|---|
| **Test ID** | UAT-R-12 |
| **Persona** | Reviewer |
| **Preconditions** | Working download URL; TTL typically 10–15 minutes (`DOCUMENTS_DOWNLOAD_TTL_SECONDS`, default 900) |
| **Steps** | 1. Generate download URL. 2. Use immediately (success). 3. Reuse after expiry (wait or use expired signature if test harness provides one). |
| **Expected result** | Fresh URL works; expired URL fails; URLs are not stored as permanent fields on the application. |
| **Evidence** | Success then failure statuses. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-R-13 — Finance / document segregation (from reviewer side)

| Field | Value |
|---|---|
| **Test ID** | UAT-R-13 |
| **Persona** | Reviewer (observe) + Finance (attempt) |
| **Preconditions** | Known document download URL pattern / reviewer document route ids |
| **Steps** | 1. As Finance, attempt reviewer document download and learner document download routes. 2. As Reviewer, confirm finance payment pages are not the document store. |
| **Expected result** | Finance denied document metadata and signed URLs; segregation enforced server-side. |
| **Evidence** | 403/404 for finance; request IDs. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |
