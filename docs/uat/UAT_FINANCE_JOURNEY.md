# UAT Finance Journey — Academy LMS (RC-01)

**Authority:** RC-01 section L  
**Persona default:** `finance@uat.example.test`  
**Overview:** [UAT_OVERVIEW.md](./UAT_OVERVIEW.md) · **Accounts:** [UAT_ACCOUNTS.md](./UAT_ACCOUNTS.md)

Complete MFA enrolment before privileged finance routes. Fill Result / Defect ref / Severity during execution.

---

## UAT-F-01 — Finance login landing

| Field | Value |
|---|---|
| **Test ID** | UAT-F-01 |
| **Persona** | Finance |
| **Preconditions** | Seeded finance user; MFA assurance complete |
| **Steps** | 1. Login. 2. Observe landing. |
| **Expected result** | Lands on finance payments or reconciliation surface; not reviewer document queue. |
| **Evidence** | Landing URL. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-02 — Payment list and detail

| Field | Value |
|---|---|
| **Test ID** | UAT-F-02 |
| **Persona** | Finance |
| **Preconditions** | Seeded/live payments (`UAT-PAYPEND-001`, `UAT-AWAIT-001`, or journey-created) |
| **Steps** | 1. GET `/finance/payments`. 2. Open `/finance/payments/{paymentId}`. |
| **Expected result** | List/detail show payment public references, status, amounts, application linkage; **no** document metadata or signed URLs. |
| **Evidence** | List screenshot; detail fields present/absent checklist. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-03 — Pending / failed / reconciliation states

| Field | Value |
|---|---|
| **Test ID** | UAT-F-03 |
| **Persona** | Finance |
| **Preconditions** | Representative statuses available (pending, failed if present, reconciliation_pending) |
| **Steps** | 1. Locate each status in list/detail. 2. Confirm labels match Payment state machine vocabulary. |
| **Expected result** | States visible and accurate; no fabricated “mark paid” control. |
| **Evidence** | Status samples. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-04 — Reconciliation retry

| Field | Value |
|---|---|
| **Test ID** | UAT-F-04 |
| **Persona** | Finance |
| **Preconditions** | Payment in reconciliation_pending (`UAT-AWAIT-001` or live); gateway/fake adapter can resolve |
| **Steps** | 1. Open reconciliation UI (`/finance/reconciliation` if listed). 2. POST `/finance/payments/{paymentId}/reconcile` with CSRF. 3. Optionally also run `php bin/jobs.php payment:reconcile`. |
| **Expected result** | Retry claims with lease fencing; status progresses or retries safely; duplicate concurrent retries do not double-apply admission. |
| **Evidence** | Before/after status; worker/UI outcome. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-05 — Duplicate captured payment visibility

| Field | Value |
|---|---|
| **Test ID** | UAT-F-05 |
| **Persona** | Finance |
| **Preconditions** | Test harness or scripted duplicate capture scenario available in UAT; otherwise N/A with note |
| **Steps** | 1. Produce or locate duplicate capture attempt against same application/order. 2. Inspect finance detail. |
| **Expected result** | Duplicate is visible for finance investigation; system does not create a second successful payable outcome / second Enrolment; Mode A invariants hold. |
| **Evidence** | Payment rows; enrolment count = 0 or 1 as appropriate. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-06 — Full batch after capture

| Field | Value |
|---|---|
| **Test ID** | UAT-F-06 |
| **Persona** | Finance (+ Ops observation) |
| **Preconditions** | Batch at capacity edge case available (concurrency suite / dedicated UAT fixture); otherwise N/A |
| **Steps** | 1. Drive capture when last seat contention exists. 2. Observe payment and application outcomes. |
| **Expected result** | Capacity rules prevent double seating; finance can see payment outcome needing operational follow-up if applicable; no silent second Enrolment. |
| **Evidence** | Application/payment statuses; enrolment uniqueness. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-07 — CSRF and permission denial

| Field | Value |
|---|---|
| **Test ID** | UAT-F-07 |
| **Persona** | Finance; Learner (negative) |
| **Preconditions** | Known reconcile endpoint |
| **Steps** | 1. As Finance, POST reconcile without CSRF → expect rejection. 2. As Learner, GET/POST finance routes → expect 403. |
| **Expected result** | CSRF enforced; finance permissions required; no state change on denied requests. |
| **Evidence** | Status codes; request IDs. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-08 — Finance cannot access documents

| Field | Value |
|---|---|
| **Test ID** | UAT-F-08 |
| **Persona** | Finance |
| **Preconditions** | Known application and document submission ids from learner/reviewer path |
| **Steps** | 1. Attempt `/applications/{id}/documents`. 2. Attempt learner and reviewer download URLs. 3. Attempt reviewer verify/reject POSTs. |
| **Expected result** | All document access denied; no metadata in error bodies. |
| **Evidence** | Status codes; empty/error bodies without document fields. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-09 — No manual mark-paid

| Field | Value |
|---|---|
| **Test ID** | UAT-F-09 |
| **Persona** | Finance |
| **Preconditions** | Pending payment detail open |
| **Steps** | 1. Inspect UI and guessable routes for mark-paid / force-success. 2. Attempt any discovered POST. |
| **Expected result** | No mark-paid action in Mode A slice; payment confirmation remains webhook/reconcile path only. |
| **Evidence** | UI checklist; denied route statuses. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-F-10 — No raw webhook / secret exposure

| Field | Value |
|---|---|
| **Test ID** | UAT-F-10 |
| **Persona** | Finance |
| **Preconditions** | Payment detail and any webhook-related admin views |
| **Steps** | 1. Inspect HTML/JSON for webhook secrets, signing keys, raw provider bodies. 2. View page source. |
| **Expected result** | No Razorpay secrets, signatures, or raw webhook payloads in finance UI. |
| **Evidence** | Negative search notes (what was checked). |
| **Result** | |
| **Defect ref** | |
| **Severity** | |
