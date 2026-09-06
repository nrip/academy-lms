# UAT Learner Journey — Academy LMS (RC-01)

**Authority:** RC-01 section J  
**Persona default:** `learner@uat.example.test` (unless a case creates a fresh registrant)  
**Overview:** [UAT_OVERVIEW.md](./UAT_OVERVIEW.md) · **Accounts:** [UAT_ACCOUNTS.md](./UAT_ACCOUNTS.md)

Fill **Result** / **Defect ref** / **Severity** during execution. Do not edit the database for ordinary cases.

---

## UAT-L-01 — Registration

| Field | Value |
|---|---|
| **Test ID** | UAT-L-01 |
| **Persona** | New registrant (unique email under tester control) |
| **Preconditions** | App reachable; registration open; email adapter configured for UAT (local_file / recording / explicit provider) |
| **Steps** | 1. GET `/register`. 2. Submit valid registration (unique email, mobile, password, terms/privacy). 3. Land on pending / verification guidance. |
| **Expected result** | Account created; user directed to email verification path; no privileged landing; password never echoed. |
| **Evidence** | Screenshot of pending page; request ID; email artifact reference (path/id only). |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-02 — Email verification

| Field | Value |
|---|---|
| **Test ID** | UAT-L-02 |
| **Persona** | Registrant from UAT-L-01 |
| **Preconditions** | Pending email verification; delivery artifact available in UAT adapter |
| **Steps** | 1. Open verification link from artifact. 2. Confirm via `/verify-email/confirm` as prompted. 3. Observe result page. |
| **Expected result** | Email marked verified; clear success; invalid/expired token rejected safely. |
| **Evidence** | Result page; request ID; no OTP/token in defect notes. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-03 — Mobile verification

| Field | Value |
|---|---|
| **Test ID** | UAT-L-03 |
| **Persona** | Registrant with verified email |
| **Preconditions** | SMS adapter available in this UAT (recording/local) **or** mark N/A if SMS unavailable by design for this environment |
| **Steps** | 1. GET `/verify-mobile`. 2. Request/resend OTP. 3. Submit correct OTP. 4. Retry with wrong OTP once. |
| **Expected result** | Correct OTP verifies mobile; wrong OTP fails without account enumeration beyond existing policy; rate limits respected. |
| **Evidence** | Success/failure screens; note if SMS N/A with register link. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-04 — Login

| Field | Value |
|---|---|
| **Test ID** | UAT-L-04 |
| **Persona** | `learner@uat.example.test` **or** fully verified registrant |
| **Preconditions** | Active account; known UAT password |
| **Steps** | 1. GET `/login`. 2. Submit correct credentials. 3. Confirm landing (dashboard/profile/courses per permissions). 4. Logout via POST `/logout`. 5. Login again with wrong password once. |
| **Expected result** | Successful login lands on allow-listed destination; failed login does not reveal whether email exists beyond documented behaviour; session regenerated. |
| **Evidence** | Landing URL; failed-login message (redacted). |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-05 — Profile completion

| Field | Value |
|---|---|
| **Test ID** | UAT-L-05 |
| **Persona** | Learner |
| **Preconditions** | Authenticated learner session |
| **Steps** | 1. Open `/profile` and `/profile/personal`. 2. Update personal fields. 3. Open `/profile/professional` and update. 4. Save each form with CSRF token present. |
| **Expected result** | Values persist; validation errors are field-level; CSRF required on POST. |
| **Evidence** | Before/after field values (non-sensitive). |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-06 — Qualification entry

| Field | Value |
|---|---|
| **Test ID** | UAT-L-06 |
| **Persona** | Learner |
| **Preconditions** | Authenticated; profile reachable |
| **Steps** | 1. GET `/profile/qualifications`. 2. Add a qualification. 3. Update it. 4. Delete it (or leave one valid row for later application). |
| **Expected result** | CRUD succeeds within validation rules; no cross-user qualification IDs accepted. |
| **Evidence** | Qualification list screenshot. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-07 — Course catalogue

| Field | Value |
|---|---|
| **Test ID** | UAT-L-07 |
| **Persona** | Learner (or anonymous if catalogue is public) |
| **Preconditions** | Demo catalogue seeded (`WP02-DEMO-OBESITY-101`) |
| **Steps** | 1. GET `/courses`. 2. Open course detail by slug. 3. Confirm published version content visible; no draft-only admin fields. |
| **Expected result** | Catalogue lists demo course; detail matches published CourseVersion; immutable version rules not violated by UI. |
| **Evidence** | Course title/code; URL. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-08 — Batch selection

| Field | Value |
|---|---|
| **Test ID** | UAT-L-08 |
| **Persona** | Learner |
| **Preconditions** | Open batch `WP02-DEMO-OBESITY-101-OPEN` exists |
| **Steps** | 1. GET course batches. 2. Open `/batches/{batchId}` for the open batch. 3. Start application when eligible. |
| **Expected result** | Open batch selectable; closed/full batches not offerable for new applications (per seeded state). |
| **Evidence** | Batch code; application create redirect/id. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-09 — Draft application

| Field | Value |
|---|---|
| **Test ID** | UAT-L-09 |
| **Persona** | Learner |
| **Preconditions** | Authenticated; open batch |
| **Steps** | 1. POST `/applications` for the batch. 2. Open application show/edit. 3. Accept declaration when required. 4. Leave in Draft without submit. |
| **Expected result** | Application status Draft; owned only by learner; appears on dashboard. Seeded `UAT-DRAFT-001` may be used as alternate fixture. |
| **Evidence** | Application id/number; status label. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-10 — Document upload

| Field | Value |
|---|---|
| **Test ID** | UAT-L-10 |
| **Persona** | Learner |
| **Preconditions** | Draft/in-progress application; document requirements present; UAT storage driver (`local` or configured S3) |
| **Steps** | 1. Open documents UI. 2. Request upload authorization. 3. Upload allowed file type within size limit (local upload route if local driver). 4. Confirm upload. 5. Run `php bin/jobs.php document:scan` if status waits on scan. |
| **Expected result** | Submission enters scan/clean pipeline; object not publicly readable; learner can see own document metadata only. |
| **Evidence** | Document status; submission id; worker stdout `document:scan processed=…`. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-11 — Application submission

| Field | Value |
|---|---|
| **Test ID** | UAT-L-11 |
| **Persona** | Learner |
| **Preconditions** | Required documents clean/complete; declaration accepted |
| **Steps** | 1. POST submit. 2. Open submission result. 3. Confirm status leaves Draft (Submitted / Under Review per Mode A rules). |
| **Expected result** | Valid transition; audit/outbox side effects queued; dashboard next action updates. |
| **Evidence** | Status; submission-result page; optional `notification:deliver` after relay. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-12 — Correction request and resubmission

| Field | Value |
|---|---|
| **Test ID** | UAT-L-12 |
| **Persona** | Learner (+ Reviewer for setup) |
| **Preconditions** | Application in resubmission_requested (`UAT-CORRECT-001` or reviewer-requested on live app) |
| **Steps** | 1. Learner opens `/applications/{id}/corrections`. 2. Replace/fix required documents. 3. POST resubmit corrections. 4. Confirm return to review path. |
| **Expected result** | Resubmission accepted; status returns toward Under Review; old rejected docs not treated as sufficient without replacement rules. |
| **Evidence** | Correction UI; new statuses. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-13 — Payment initiation

| Field | Value |
|---|---|
| **Test ID** | UAT-L-13 |
| **Persona** | Learner |
| **Preconditions** | Application in Payment Pending (live path or after reviewer approval); fake or Razorpay gateway configured for UAT |
| **Steps** | 1. Open `/applications/{id}/payment`. 2. POST initiate payment. 3. Observe checkout handoff / fake gateway UI. |
| **Expected result** | Payment row created with `application_id`; amount/currency match server quote; no Enrolment yet. |
| **Evidence** | Payment public reference; amount; status Created/Pending. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-14 — Confirming payment result (browser return)

| Field | Value |
|---|---|
| **Test ID** | UAT-L-14 |
| **Persona** | Learner |
| **Preconditions** | Checkout return available (fake gateway or test checkout) |
| **Steps** | 1. Complete hosted/fake checkout. 2. Hit checkout-return / payment-result pages. 3. Read messaging carefully. |
| **Expected result** | UI shows confirming / pending confirmation — **not** final “Successful” as sole authority. Application does not jump to Admitted solely from browser return. |
| **Evidence** | Screenshot of result copy; application status unchanged pending webhook. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-15 — Signed webhook simulation (UAT)

| Field | Value |
|---|---|
| **Test ID** | UAT-L-15 |
| **Persona** | System / Ops (not learner browser trust) |
| **Preconditions** | Payment Pending/Created; UAT webhook signing secret configured; fake or test event payload available |
| **Steps** | 1. POST `/webhooks/razorpay` with **valid** signature for a capture event matching order/amount. 2. Run `php bin/jobs.php payment:webhook-process`. 3. If reconciliation pending, run `payment:reconcile`. 4. Run notification workers as needed. |
| **Expected result** | Event persisted idempotently; payment transitions via state machine; on Mode A completion Application → Admitted and Enrolment created exactly once. |
| **Evidence** | Payment status; application status; enrolment public reference; worker stdout. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-16 — Admitted state

| Field | Value |
|---|---|
| **Test ID** | UAT-L-16 |
| **Persona** | Learner |
| **Preconditions** | Successful UAT-L-15 **or** seeded `UAT-ADMIT-*` |
| **Steps** | 1. Open application detail. 2. Confirm Admitted. 3. Confirm enrolment exists and links to same application. |
| **Expected result** | Admitted is terminal for Mode A happy path; exactly one enrolment per application. |
| **Evidence** | Status + enrolment reference. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-17 — Enrolment on dashboard

| Field | Value |
|---|---|
| **Test ID** | UAT-L-17 |
| **Persona** | Learner |
| **Preconditions** | Scheduled and/or Active enrolment (seeded or live) |
| **Steps** | 1. GET `/dashboard`. 2. Locate application, payment summary, enrolment lifecycle. 3. Follow next-action links. |
| **Expected result** | Dashboard shows Applications, Payment, Enrolment (Scheduled/Active only in slice); no player/progress/certificate surfaces. |
| **Evidence** | Dashboard screenshot. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-18 — Cross-user access denial

| Field | Value |
|---|---|
| **Test ID** | UAT-L-18 |
| **Persona** | Learner A vs Learner B (create second learner or use multi without sharing ids) |
| **Preconditions** | Two distinct learner-owned applications |
| **Steps** | 1. As B, request A’s `/applications/{id}`, documents, payment, dashboard-only data by id. |
| **Expected result** | 403/404 per policy; no document metadata leakage. |
| **Evidence** | Status codes; request IDs. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-L-19 — Logout / login return behaviour

| Field | Value |
|---|---|
| **Test ID** | UAT-L-19 |
| **Persona** | Learner |
| **Preconditions** | Authenticated session; optional allow-listed `return_to` |
| **Steps** | 1. Logout. 2. Login with `return_to` to an allow-listed path. 3. Retry with external `return_to` (e.g. `https://evil.example`). |
| **Expected result** | Allow-listed return works; external return ignored/rejected; session cookies cleared on logout. |
| **Evidence** | Final landing URLs. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |
