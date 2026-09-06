# UAT Security Negative Tests — Academy LMS (RC-01)

**Authority:** RC-01 section N  
**Overview:** [UAT_OVERVIEW.md](./UAT_OVERVIEW.md)

Any Fail here is **Critical** (or Blocker) unless Product + Security explicitly waive with written risk acceptance.

---

## UAT-S-01 — Unauthenticated protected route

| Field | Value |
|---|---|
| **Test ID** | UAT-S-01 |
| **Persona** | Anonymous |
| **Preconditions** | Logged out; clear cookies |
| **Steps** | Request `/dashboard`, `/finance/payments`, `/reviewer/applications`, `/admin/notifications`, an application id URL. |
| **Expected result** | Redirect to login or 401/403; no sensitive payload. |
| **Evidence** | Status/Location headers; request IDs. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-02 — Cross-user application access

| Field | Value |
|---|---|
| **Test ID** | UAT-S-02 |
| **Persona** | Learner B |
| **Preconditions** | Application owned by Learner A |
| **Steps** | As B, GET/POST A’s application show/edit/submit routes. |
| **Expected result** | Denied; no status leakage beyond policy. |
| **Evidence** | Status codes. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-03 — Cross-user dashboard access

| Field | Value |
|---|---|
| **Test ID** | UAT-S-03 |
| **Persona** | Learner B |
| **Preconditions** | A has enrolments/applications |
| **Steps** | As B, open `/dashboard` and any guessed user-id query params. |
| **Expected result** | Dashboard scoped to auth user only; no param override. |
| **Evidence** | Visible application numbers belong only to B. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-04 — Cross-user document access

| Field | Value |
|---|---|
| **Test ID** | UAT-S-04 |
| **Persona** | Learner B |
| **Preconditions** | Document submission ids from A |
| **Steps** | Attempt A’s document download and confirm routes. |
| **Expected result** | Denied; no file bytes. |
| **Evidence** | Status codes. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-05 — Finance document access

| Field | Value |
|---|---|
| **Test ID** | UAT-S-05 |
| **Persona** | Finance |
| **Preconditions** | MFA complete; known document ids |
| **Steps** | Attempt all document download/metadata routes (learner + reviewer). |
| **Expected result** | Denied at authorization/repository layer. |
| **Evidence** | Status codes. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-06 — Reviewer outside assigned scope

| Field | Value |
|---|---|
| **Test ID** | UAT-S-06 |
| **Persona** | Reviewer |
| **Preconditions** | Out-of-scope application id (or N/A) |
| **Steps** | Direct GET/claim/approve URLs. |
| **Expected result** | Denied. |
| **Evidence** | Status codes. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-07 — Missing / invalid CSRF

| Field | Value |
|---|---|
| **Test ID** | UAT-S-07 |
| **Persona** | Authenticated learner/reviewer/finance |
| **Preconditions** | Any state-changing form |
| **Steps** | POST without token; POST with garbage token. |
| **Expected result** | Rejected; no state change. |
| **Evidence** | Status; unchanged entity status. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-08 — External return_to

| Field | Value |
|---|---|
| **Test ID** | UAT-S-08 |
| **Persona** | Learner |
| **Preconditions** | Login form |
| **Steps** | Login with `return_to=https://evil.example/phish`. |
| **Expected result** | External URL not used; safe default landing. |
| **Evidence** | Final Location. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-09 — Suspended account

| Field | Value |
|---|---|
| **Test ID** | UAT-S-09 |
| **Persona** | Suspended user (create via controlled UAT admin/support path or fixture) |
| **Preconditions** | Account status suspended |
| **Steps** | Attempt login and direct privileged URLs with old session if any. |
| **Expected result** | No privileged landing; session invalidated or blocked. |
| **Evidence** | Status/messages (no enumeration beyond policy). |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-10 — Pending-verification account

| Field | Value |
|---|---|
| **Test ID** | UAT-S-10 |
| **Persona** | Newly registered, unverified email |
| **Preconditions** | Registration completed; email not verified |
| **Steps** | Attempt login and protected learner routes. |
| **Expected result** | Cannot reach operational landings; verification guidance only. |
| **Evidence** | Landing path. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-11 — Incomplete MFA on privileged route

| Field | Value |
|---|---|
| **Test ID** | UAT-S-11 |
| **Persona** | Reviewer/Finance/Ops after password auth, before MFA complete |
| **Preconditions** | Fresh seed user without MFA device |
| **Steps** | 1. Login with password. 2. Request `/reviewer/applications` or `/finance/payments` or `/admin/notifications` before finishing MFA. |
| **Expected result** | Blocked except MFA allow-list routes; no queue/payment/notification data. |
| **Evidence** | Redirect/status to MFA enrolment/challenge. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-12 — Oversized upload

| Field | Value |
|---|---|
| **Test ID** | UAT-S-12 |
| **Persona** | Learner |
| **Preconditions** | Document upload path; platform cap (credential docs 10 MB) |
| **Steps** | Attempt upload above cap. |
| **Expected result** | Rejected before/at authorization; no stored object treated as clean. |
| **Evidence** | Error response. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-13 — Invalid file type

| Field | Value |
|---|---|
| **Test ID** | UAT-S-13 |
| **Persona** | Learner |
| **Preconditions** | Upload path |
| **Steps** | Upload disallowed type / mismatched signature vs declared MIME. |
| **Expected result** | Rejected; does not enter reviewer queue as clean. |
| **Evidence** | Error; submission status if any. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-14 — Invalid webhook signature

| Field | Value |
|---|---|
| **Test ID** | UAT-S-14 |
| **Persona** | External caller |
| **Preconditions** | Known webhook endpoint; wrong signature |
| **Steps** | POST `/webhooks/razorpay` with invalid signature. |
| **Expected result** | HTTP 400; event not processed as payment success; no admission. |
| **Evidence** | Status; payment/application unchanged. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-15 — Duplicate webhook

| Field | Value |
|---|---|
| **Test ID** | UAT-S-15 |
| **Persona** | External caller / Ops |
| **Preconditions** | Valid signed event already processed |
| **Steps** | Replay identical event; run `payment:webhook-process` again. |
| **Expected result** | Idempotent; no duplicate payment success side effects; no second Enrolment. |
| **Evidence** | Payment/enrolment counts. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-16 — Fake adapter access in production-like mode

| Field | Value |
|---|---|
| **Test ID** | UAT-S-16 |
| **Persona** | Ops (config review) |
| **Preconditions** | Separate staging-like config check **or** temporary local proof that `APP_ENV=staging|production` refuses fake drivers |
| **Steps** | 1. Confirm EnvironmentCapability / validator rejects fake payment, fake scanner, local storage when production-like. 2. Confirm UAT explicitly enables fakes only via flags. |
| **Expected result** | Staging/production fail closed; UAT requires explicit configuration. |
| **Evidence** | Validator/setup error messages (no secrets). |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-17 — Direct GET mutation attempts

| Field | Value |
|---|---|
| **Test ID** | UAT-S-17 |
| **Persona** | Authenticated users |
| **Preconditions** | Known state-changing actions (claim, approve, reconcile, retry, logout) |
| **Steps** | Issue GET to mutation URLs. |
| **Expected result** | No state change (405/404/redirect without mutation). |
| **Evidence** | Entity status unchanged. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-S-18 — Route permission bypass attempts

| Field | Value |
|---|---|
| **Test ID** | UAT-S-18 |
| **Persona** | Learner; Reviewer; Finance |
| **Preconditions** | Cross-role route map |
| **Steps** | Learner → finance/reviewer/admin notification; Reviewer → finance reconcile; Finance → reviewer approve. |
| **Expected result** | All denied; UI hiding is insufficient — server enforces. |
| **Evidence** | Matrix of status codes. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |
