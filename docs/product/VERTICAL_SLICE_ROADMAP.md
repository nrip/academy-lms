# Vertical Slice Roadmap — Admission Journey (Mode A)

**Status:** Approved logical build plan (amended 2026-07-19)  
**Base:** Phase 0 (`foundation/repository-scaffold`)  
**Planning branch:** `plan/vertical-slice-admission`  
**Authority:** PRD → SRS v6.0 → Technical Architecture → High-fidelity UX → Screen Inventory → Decision Log → this roadmap.  
**State machines:** SRS §18 + AGENTS.md + [`STATE_MACHINE_ADDENDUM.md`](./STATE_MACHINE_ADDENDUM.md) (authoritative for DocumentSubmission until a future SRS revision).

## Slice constraints (approved)

- Admission **Mode A only**
- **No waitlist**
- **Razorpay only** (HDFC deferred)
- **No Course Admin builder UI** (synthetic published seed only)
- **Learner Dashboard only**; Course Player deferred
- Finance role present **only for segregation-of-duties tests**
- Published / locked CourseVersions remain **immutable**
- Payment attempt rule: see Decision Log **PAY-ATTEMPT-1**

## Logical stages and PR boundaries

| Logical WP | Delivery | Branch |
| --- | --- | --- |
| **WP-01** Identity, Session, RBAC and Audit Foundation | **Two separately reviewed PRs** | see 01A / 01B |
| WP-01A Security and Audit Foundation | PR 1 of logical WP-01 | `slice/wp01a-security-audit-foundation` |
| WP-01B Identity, RBAC and Reviewer MFA | PR 2 of logical WP-01 | `slice/wp01b-identity-rbac-mfa` |
| WP-02 Course Detail, Batch Select and Draft Application | One PR | `slice/wp02-course-detail-draft-application` |
| WP-03 Application Submission and Credential Documents | One PR | `slice/wp03-application-documents` |
| WP-04 Reviewer Verification | One PR | `slice/wp04-reviewer-verification` |
| WP-05 Payment Checkout | One PR | `slice/wp05-payment-checkout` |
| WP-06 Webhook, Admission and Enrolment | One PR | `slice/wp06-webhook-admit-enrolment` |
| WP-07 Learner Dashboard and UAT Hardening | One PR | `slice/wp07-learner-dashboard-uat` |

```text
WP-01A ──► WP-01B ──► WP-02 ──► WP-03 ──► WP-04 ──► WP-05 ──► WP-06 ──► WP-07
```

Transactional notifications **beyond identity OTP** land in **WP-07** (no separate notifications branch). OTP for registration/verification may ship in WP-01B.

## WP-01A — Security and Audit Foundation

**Branch:** `slice/wp01a-security-audit-foundation`

**In scope:**

- `audit_log` schema and append-only writer
- Transactional outbox
- Shared **MySQL** session store and **MySQL** rate-limit store (separate tables; hashed session IDs; indexed expiries; scheduled cleanup; atomic rate-limit updates; documented failure behaviour) per WP01-A
- CSRF middleware
- Rate limiting (Architecture §5.2.1)
- Security middleware completion (session + CSRF + rate limit wired into Phase 0 pipeline)
- Foundational security tests
- Record load-test / horizontal-scaling review triggers from WP01-A in ops notes or Decision Log cross-ref

**Out of scope for WP-01A:**

- Registration/login/profile UI
- RBAC seed beyond what middleware contracts need
- Reviewer MFA
- Document routes, DocumentSubmission repositories, or Finance document SoD HTTP tests against fake document APIs

**Finance SoD in WP-01A/B:** Test **permission matrix / authorisation policies** only — prove Finance is not granted any document permissions. Real repository, signed-URL and HTTP Finance denial tests belong in **WP-03**.

**Depends on:** Phase 0; WP01-A and WP01-F approved (MySQL session/rate-limit store; ap-south-1 working assumption). Implement separate session and rate-limit tables per Decision Log WP01-A.

---

## WP-01B — Identity, RBAC and Reviewer MFA

**Branch:** `slice/wp01b-identity-rbac-mfa`

**In scope:**

- Registration / login / logout / password reset
- Email verification (production path once WP01-B Approved); mobile verification via provider-neutral port (sandbox/fake until WP01-C production pack Approved)
- Learner personal and professional profiles (slice-minimum)
- Roles, permissions, user_roles, role_permissions (seed Applicant/Learner, Credential Reviewer, Finance, bootstrap Super Admin)
- Reviewer object-scope: Course, CourseVersion, and/or Batch; union of active assignments; `include_future_versions` default off; effective dates, revocation history, creator/revoker, audit
- Reviewer MFA via approved `spomky-labs/otphp` under WP01-D conditions

**Depends on:** WP-01A merged; WP01-D and WP01-E approved; WP01-B email provider Approved in Decision Log before production email verification. SMS production pack (WP01-C) Preferred/Pending — sandbox adapter allowed; **blocks WP-01B production completion**.

**Out of scope:** SA-01/02 admin UIs; stub document endpoints for SoD.

---

## WP-02 — Course Detail, Batch Select and Draft Application

**Branch:** `slice/wp02-course-detail-draft-application`

**Draft rule (authoritative):**

- Initial construction of an Application in **Draft** is an **entity factory** operation, not a state-machine transition.
- After the Application is persisted, **every status change** must go through `ApplicationStateMachine`.
- Repositories must **never** expose `updateStatus()`.
- WP-02 must **not** introduce temporary raw status-writing logic intended for later replacement.

**In scope:** Course / locked Published CourseVersion / Batch / EligibilityRule / CourseDocumentRequirement schema; Mode A seed; G-02; A-03; Draft Application factory + owner read.

**Out of scope:** Application submit; documents; payments; Course Admin builders; waitlist.

---

## WP-03 — Application Submission and Credential Documents

**Branch:** `slice/wp03-application-documents`

**In scope:** Application submit + eligibility; **complete** `ApplicationStateMachine` matrix (unit-tested for every allowed and disallowed pair); DocumentSubmission upload + malware-scan gate; **complete** `DocumentSubmissionStateMachine` per State Machine Addendum; **real** Finance SoD tests (repository, signed URL, HTTP).

**Depends on:** WP-02; malware scanner + S3 + queue decisions; State Machine Addendum (`SM-DOC-1`) approved.

---

## WP-04 — Reviewer Verification

**Branch:** `slice/wp04-reviewer-verification`

**In scope:** R-01/R-02; VerificationAuditLog; Mode A path to Application **Payment Pending**; reject/resubmission via approved SMs.

---

## WP-05 — Payment Checkout

**Branch:** `slice/wp05-payment-checkout`

**In scope:** Payment attempts on Application; Razorpay order/checkout; **complete** `PaymentStateMachine` matrix; A-06/A-09 confirming UI (browser non-authoritative).

**Payment rule:** Decision Log **PAY-ATTEMPT-1**.

---

## WP-06 — Webhook, Admission and Enrolment

**Branch:** `slice/wp06-webhook-admit-enrolment`

**In scope:** Signature-verified webhook; durable idempotent gateway events; worker; capacity locking; Application → Admitted; Enrolment create; **complete** `EnrolmentStateMachine` matrix; one net successful payable outcome enforcement; optional invoice if separately approved.

---

## WP-07 — Learner Dashboard and UAT Hardening

**Branch:** `slice/wp07-learner-dashboard-uat`

**In scope:** L-01 (Scheduled/Active); transactional notifications beyond OTP; Mode A UAT + applicable Architecture §18.2 high-risk regressions.

**No separate notifications branch.**

---

## Cross-cutting rules

1. When a state machine is introduced, implement and test the **complete** approved transition matrix, not only Mode A happy-path edges.
2. Enrolment is created **only** when Application reaches Admitted.
3. Finance never accesses DocumentSubmission data, metadata, or signed URLs.
4. Reviewer access requires permission **and** object scope.
5. Sensitive transitions write atomic audit records; workers/webhooks use the same SMs as browser requests.
