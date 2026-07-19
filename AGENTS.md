# AGENTS.md — Academy LMS

> This file is read by AI coding agents (Cursor, Claude Code, Copilot, etc.) before every interaction.
> It is authoritative. If a request conflicts with these rules, **stop and raise the conflict** — do not silently comply.

---

## 1. Project Identity

**Academy LMS** is a Continuing Medical Education platform for obesity and metabolic health.
It serves doctors, nurses and allied medical professionals in India.

This is a **production system**, not a prototype. Every line of code will be reviewed, audited and run against real learner data, real payments and real medical credentials.

---

## 2. Approved Technology Stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.4 (strict_types, PSR-4, Composer) |
| Database | MySQL 8.4 LTS / InnoDB, utf8mb4 |
| Database access | PDO prepared statements only |
| Frontend | jQuery 3.x + Bootstrap 5.3 (retokened via CSS variables) |
| Admin tables | DataTables (server-side) |
| Enhanced selects | Select2 |
| Dialogs | SweetAlert2 (simple confirms only) |
| Object storage | Private AWS S3 with signed URLs |
| Queue | Amazon SQS |
| Video | Mux or Cloudflare Stream (never self-hosted) |
| Payments | Razorpay (primary), HDFC (secondary) |
| Source control | GitHub, protected `main`, PR-only merges |

**Do not introduce any framework, library or architectural pattern not listed above without explicit product approval.**

---

## 3. Directory Structure
academy-lms/
├── public/index.php
├── src/
│   ├── Domain/           # Business rules, state machines, value objects
│   │   ├── Identity/
│   │   ├── RBAC/
│   │   ├── Courses/
│   │   ├── Admissions/
│   │   ├── Credentials/
│   │   ├── Payments/
│   │   ├── Learning/
│   │   ├── Assessments/
│   │   ├── Certificates/
│   │   ├── Notifications/
│   │   ├── Support/
│   │   └── Reporting/
│   ├── Application/      # Use-case orchestration, commands, queries, DTOs
│   ├── Infrastructure/   # PDO, S3, SQS, Razorpay, Mux, Mail adapters
│   └── Http/             # Controllers, Middleware, Requests, Responses
├── templates/            # Escaped presentation only — no business logic
├── config/
├── database/migrations/
├── tests/
├── bin/
├── composer.json
└── AGENTS.md             # ← you are here


**Layering is strict.** Templates cannot run queries. Controllers cannot contain SQL. Domain cannot import PDO. Repositories cannot contain product rules. Violations are rejected at code review.

---

## 4. The 11 Mandatory Rules

These are **non-negotiable**. Violating any one of them is a blocking defect.

### Rule 1 — Never invent a missing requirement
If the SRS, PRD, wireframes or high-fidelity prototypes do not cover a case, **raise it explicitly as a question**. Do not fill the gap with your own assumption.

### Rule 2 — Never change approved status machines
The state machines in SRS §18 and Technical Architecture §4.3 are approved. Do not add, remove or reorder states or transitions without product sign-off. If a transition seems wrong, flag it — do not "fix" it silently.

### Rule 3 — Never create an Enrolment before the Application is Admitted
`Enrolment` is created **only** when `Application.status` reaches `Admitted`. This is the single most important architectural invariant. See §7 below.

### Rule 4 — Never trust a browser payment callback
Payment confirmation comes from the **server-side webhook** (Razorpay/HDFC), verified by signature. The browser's "success" page is informational only. See §7 below.

### Rule 5 — Never modify a published CourseVersion
Once a `CourseVersion` has `locked_at IS NOT NULL` (because it is Published or has Applications against it), it is immutable. Changes require cloning to Version N+1. See §7 below.

### Rule 6 — Never weaken RBAC or expose credential documents to Finance
- Finance users **cannot** access `DocumentSubmission` data or signed document URLs — ever.
- Reviewers **cannot** issue or approve refunds.
- Course Admins **cannot** approve credential documents.
- These are segregation-of-duties controls, not UI preferences. Enforce at the repository layer, not just the UI.

### Rule 7 — Every schema change requires a migration
No direct SQL in production. No hand-edited tables. Every change goes through a committed, reviewable Phinx/Doctrine migration with a reversible `down()` method.

### Rule 8 — Every sensitive state change requires an audit event
State transitions on Application, Enrolment, Payment, DocumentSubmission, Certificate, Refund, and all admin configuration changes must write to `audit_log` or `verification_audit_log` with: actor, action, entity, before/after values, reason, timestamp.

### Rule 9 — Every asynchronous handler must be idempotent
SQS jobs may be delivered more than once. Every worker must check state before acting and use idempotency keys where specified (SRS §8.3 / Architecture §8.3).

### Rule 10 — Every feature must include automated tests
Unit tests for domain rules and state transitions. Integration tests for repositories, transactions and locking. HTTP tests for permissions and validation. No feature merges without tests.

### Rule 11 — Never use real learner data or production secrets in an AI tool
Use synthetic fixtures only. Never paste production database dumps, real payment credentials, real learner PII, or real document scans into any AI-assisted coding session.

---

## 5. Key Domain Invariants

### 5.1 Application / Payment / Enrolment — the critical split
Application (pre-admission)
  ├── has many DocumentSubmissions
  ├── has one Payment  ← Payment.application_id is NOT NULL
  └── leads to at most one Enrolment (only when Admitted)
Payment
  ├── application_id NOT NULL  ← belongs to Application, not Enrolment
  └── enrolment_id NULLABLE    ← populated only after Admitted
Enrolment (post-admission)
  ├── application_id UNIQUE    ← one-to-one with Application
  ├── created ONLY when Application.status = Admitted
  └── has ContentProgress, AssessmentAttempts, Certificates


**Why this matters:**
- Mode B rejection: Payment exists, Application is Rejected, **no Enrolment was ever created** — refund closes the Application's Payment, nothing else to unwind.
- Abandoned Application: Payment may not exist; Application expires; no Enrolment.
- The split resolves three problems at once. Do not collapse it.

### 5.2 Course vs CourseVersion

- `Course` holds only stable identity: `course_id`, `course_code`, `master_title`, `product_owner`, `current_published_version_id`, `status`.
- `CourseVersion` holds everything configurable: description, curriculum, eligibility rules, required documents, fee, GST, admission mode, assessment policy, certificate rules, module structure.
- `Batch.course_version_id` is the authoritative reference — a Batch delivers one specific version.
- `Module`, `ContentItem`, `EligibilityRule`, `CourseDocumentRequirement` all belong to `CourseVersion`, **not** `Course`.

### 5.3 CourseVersion Immutability

A `CourseVersion` becomes immutable when:
- it is Published, OR
- any Application references it

...whichever occurs first.

**Controls:**
- `course_versions.locked_at` and `locked_reason` columns.
- Application service refuses update/delete when `locked_at IS NOT NULL`.
- Child services apply the same guard to modules, content, eligibility rules, document requirements, assessment policies, certificate rules.
- Database `BEFORE UPDATE` / `BEFORE DELETE` triggers reject changes as defence-in-depth.
- Foreign keys use `RESTRICT`, not cascading deletion.
- Publish and clone actions are audited.

**The trigger is defence-in-depth, not the UX.** The application must return a clear conflict message and offer "Create Version N+1" — never rely on a raw trigger exception as the user experience.

### 5.4 Payment belongs to Application

- `payments.application_id` is `NOT NULL`.
- `payments.enrolment_id` is `NULLABLE`, populated only after Application reaches Admitted.
- This is what keeps Mode B rejection, failed payments, abandoned applications, and refunds-without-enrolment all straightforward.

---

## 6. State Machines

### 6.1 Application Status (11 states)
Draft → [Submitted, Withdrawn]
Submitted → [Documents Incomplete, Under Review, Payment Pending]
Documents Incomplete → [Under Review]
Under Review → [Resubmission Requested, Payment Pending, Rejected]
Resubmission Requested → [Under Review, Expired]
Payment Pending → [Awaiting Verification, Admitted, Cancelled]
Awaiting Verification → [Admitted, Rejected]
Admitted → [] (terminal; triggers Enrolment creation)
Rejected → [Withdrawn]
Withdrawn → [] (terminal)
Expired → [] (terminal)


### 6.2 Enrolment Lifecycle Status (7 states)

Scheduled → [Active, Cancelled]
Active → [Suspended, Withdrawn, Access Expired]
Suspended → [Active, Withdrawn]
Withdrawn → [Refunded] (only via approved refund workflow)
Access Expired → [Refunded] (only via approved refund workflow)
Cancelled → [] (terminal)
Refunded → [] (terminal)


### 6.3 Payment Status (10 states)
Created → Pending → Successful → [Refunded, Partially Refunded, Disputed]
                → Failed
                → Cancelled
                → Expired
                → Reconciliation Pending

### 6.4 State Machine Enforcement

All transitions go through dedicated state machine classes:
- `ApplicationStateMachine`
- `EnrolmentStateMachine`
- `PaymentStateMachine`
- `DocumentSubmissionStateMachine`

**Rules:**
- Only application services may call `transition()`.
- Repositories expose **no** general `updateStatus()` method.
- `transition()` runs inside the same transaction as dependent writes, audit records and outbox events.
- Current row is loaded with `SELECT ... FOR UPDATE` for payment-, admission-, capacity- or refund-sensitive transitions.
- Every transition records `from_state`, `to_state`, `actor`, `reason`, `source`, `timestamp`, `correlation_id`.
- Workers and webhook handlers use the **same** state machine as browser requests — no parallel transition logic.
- Invalid transitions return HTTP 409 and do not partially update related records.
- Unit tests cover every allowed and every disallowed transition.

---

## 7. RBAC & Segregation of Duties

### 7.1 Model

- `User` has many `Role` via `user_roles` (many-to-many).
- `Role` has many `Permission` via `role_permissions` (many-to-many).
- Effective permissions = `UNION` of all permissions across all user's roles.
- Permission check = server-side, every protected route and action.
- Object scope = permission alone is insufficient; assigned batch/course or ownership scope must also be checked.

### 7.2 Hard Segregation Boundaries

| Role | Cannot |
|---|---|
| Finance Administrator | View qualification documents, generate signed document URLs, view document metadata |
| Credential Reviewer | Issue or approve refunds, view payment data, alter course pricing |
| Course Administrator | Approve credential documents, access VerificationAuditLog |
| Support Executive | Alter academic records, payments or documents directly |

**Enforcement:** Repository methods check permission before returning data. UI-level checks are for UX only — they do NOT replace repository-level enforcement.

### 7.3 MFA

Mandatory for: Super Admin, Course Admin, Credential Reviewer, Finance Admin.
TOTP preferred. Recovery codes hashed and single-use.

---

## 8. Coding Standards

### 8.1 PHP

- `declare(strict_types=1);` in every PHP file.
- One class per file, PSR-4 namespaces.
- Prefer `final` classes unless inheritance is required.
- Typed parameters, return types and properties everywhere.
- Immutable DTOs / value objects where practical.
- Constructor injection for dependencies — no service locators, no static global state.
- Domain-specific exception types:
  - `ValidationException`
  - `AuthenticationException`
  - `AuthorizationException`
  - `NotFoundException`
  - `ConflictException`
  - `DomainRuleException`
  - `ExternalServiceException`
- Functions small and single-purpose.
- Comments explain **why**, not what obvious code does.

### 8.2 SQL

- PDO prepared statements only. **Never** concatenate user input into SQL.
- Dynamic `ORDER BY` or column names from explicit allow-lists only.
- `DECIMAL` for money — never `FLOAT` or `DOUBLE`.
- All timestamps stored as UTC. Timezone conversion in PHP presentation layer only.
- Foreign keys mandatory unless a documented exception is approved.
- `BIGINT UNSIGNED` for IDs.

### 8.3 Frontend

- Use native Bootstrap classes restyled via the token layer (`--bs-primary`, etc.).
- Custom components use `acad-` prefix with BEM modifiers: `acad-pill--success`, `acad-card-state--scheduled`.
- ES modules or clearly namespaced modules — no global variables.
- Delegated event handlers for dynamic DataTables content.
- All AJAX requests include CSRF token.
- Handle 401, 403, 409, 422, 500 consistently.
- Client validation improves UX but **never replaces server validation**.
- Use `data-*` attributes for IDs — never parse identifiers from visible text.
- Debounce search and autosave requests.
- Do not embed business-state transitions solely in JavaScript.

### 8.4 Forbidden Patterns

- No `eval()`, dynamic `include` paths from user input, or `unserialize()` on untrusted data.
- No stack traces or SQL errors exposed to users.
- No credentials in JavaScript, HTML source, logs or repository files.
- No reliance on disabled buttons or hidden links for security.
- No unrestricted administrative impersonation.
- No permanent signed URLs (10–15 minute expiry max).
- No direct SQL in templates.
- No state-changing GET requests.
- No unbounded `SELECT *` for user-facing lists.

---

## 9. Payment Architecture

### 9.1 Flow

1. Server calculates price, discounts, GST and payable total.
2. Server creates a `Payment` linked to `Application`.
3. Server creates the gateway order and stores the gateway order reference.
4. Browser launches the hosted gateway checkout.
5. Browser return displays **"Confirming payment…"** — NOT "Successful".
6. Webhook verifies signature and persists the event.
7. Worker fetches/validates captured status and amount.
8. Application transitions only if the event is valid and idempotent.
9. If all admission conditions are complete, Application becomes Admitted and Enrolment is created.

### 9.2 Webhook Endpoint
POST /webhooks/razorpay

    Read raw request bytes
    Verify Razorpay signature
    Reject invalid signatures (400)
    Persist gateway event with unique event key (idempotency)
    Return HTTP 200 rapidly after durable receipt
    Queue processing
    NEVER trust browser redirect as payment confirmation


### 9.3 Idempotency Keys

| Operation | Key |
|---|---|
| Razorpay webhook | Gateway event ID with unique constraint |
| Payment order creation | Application + payable version/order key |
| Enrolment creation | `UNIQUE enrolments.application_id` |
| Certificate generation | Enrolment + certificate type + entitlement/version |
| Notification | Event type + recipient + domain event ID |
| Queue job | Job idempotency key + handler-side state check |
| Waitlist acceptance | Row lock + status transition Offered → Accepted |

### 9.4 Financial Validation

- Captured amount and currency must equal the server-generated order.
- Only one net successful payable outcome per Application unless Finance resolves a duplicate.
- Cumulative completed refunds may not exceed the captured refundable amount.
- Every manual financial action requires permission, reason and AuditLog entry.

---

## 10. Document Storage & Malware Scanning

- Files stored in private S3. MySQL stores metadata, status and object keys only.
- Signed URLs generated on demand, 10–15 minute expiry, never stored as permanent fields.
- Upload flow: server validates → presigned URL → client uploads direct to S3 → scan → only clean files enter reviewer queue.
- Validate file signatures, not only client MIME declarations.
- Random object keys; original names preserved only as metadata.
- Platform caps: credential documents 10 MB; support attachments 10 MB / 5 files; downloadable resources 100 MB; profile images 5 MB; webhook bodies 1 MB.

---

## 11. Testing Requirements

### 11.1 Test Layers

| Layer | Purpose |
|---|---|
| Unit | Domain rules, calculations, state-transition policies, validators |
| Repository integration | SQL, constraints, migrations, transaction and locking behaviour |
| HTTP integration | Authentication, CSRF, permissions, validation, response contracts |
| Worker integration | Retries, idempotency, dead-letter, external-service adapters |
| Browser / E2E | Critical learner and administrator journeys |
| Security | Authorisation matrix, upload controls, injection, session behaviour |
| Performance | Peak course access, assessment autosave, admin tables, exports |

### 11.2 Mandatory High-Risk Tests

These must pass before any merge:

1. Finance users cannot access document metadata or signed document URLs.
2. Reviewer cannot issue or approve refunds.
3. Course Administrator cannot approve credentials.
4. Mode B rejection produces no Enrolment and closes/refunds the Application-linked Payment.
5. Duplicate webhook delivery does not duplicate payment, invoice, enrolment or notification.
6. Two users cannot accept the last batch seat.
7. Published CourseVersion configuration cannot be modified.
8. Cumulative refunds cannot exceed captured payment.
9. Assessment answers survive transient connection failure.
10. Certificate cannot issue before configured participation/completion conditions.
11. Revoked certificates return revoked state publicly without contact data.

---

## 12. Work-Package Format

When implementing a feature, use this format (per Technical Architecture §21.2):
Title:
SRS references:
UX screen IDs:
Business preconditions:
Expected state transitions:
Database changes:
Permissions:
Acceptance criteria:
Tests required:
Out of scope:
Files/modules allowed to change:


---

## 13. Companion Documents

These are authoritative. When in doubt, they override any inference.

| Document | Status |
|---|---|
| PRD v1.0 | Approved |
| SRS v6.0 | Conditionally Approved (functional baseline) |
| Screen Inventory v3 | Approved |
| Low-fidelity Wireframes v3 | Approved |
| High-fidelity Prototypes (9 flows) | Approved |
| Design System v1.0 | Approved |
| Technical Architecture v1.1 | Approved for Build |

**If a request conflicts with any of these documents, stop and raise the conflict.**

---

## 14. When to Stop and Ask

Stop and raise a question (do not proceed) if:

- A requirement is ambiguous or missing from the SRS/PRD/wireframes.
- A requested change would alter an approved state machine.
- A requested change would weaken RBAC or segregation of duties.
- A requested change would modify a published CourseVersion.
- You are asked to use real learner data or production secrets.
- You are asked to bypass a migration, audit log or idempotency control.
- A requested pattern contradicts the approved tech stack or architecture.

**Silent compliance with a bad request is a worse outcome than a delayed response.**

---

## 15. Review Model

- One agent may implement; a different model or human should review high-risk changes.
- Human technical approval is **mandatory** for: auth, RBAC, payments, documents, refunds, assessment scoring, certificates.
- No agent may merge its own high-risk pull request.
- AI-generated code is subject to the same gates as human-written code.

---

*Last updated: July 2026*
*Companion to: Technical Architecture v1.1, SRS v6.0*                