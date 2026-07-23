**SOFTWARE REQUIREMENTS SPECIFICATION**

Academy Learning Management System - Version 6.1

Date: 2026-07-23

Domain: Continuing Medical Education - Obesity & Metabolic Disorders

Audience: Doctors, Nurses, Allied Medical Staff and configurable future professional categories

Source document: Product Requirements Document v1.0 (Academy Management) - this SRS translates that PRD's approved business rules into buildable functional and technical requirements

Prepared for: Product Management & Engineering

Document status: Conditionally Approved Functional SRS - consolidation release (supersedes SRS v1.0–v5.0 for build baseline purposes; **SRS v6.0 remains on record and is not overwritten**)

## Revision history

| Version | Date | Description |
| --- | --- | --- |
| 6.0 | (prior release) | Conditionally Approved Functional SRS — Application/Payment/Enrolment split, CourseVersion ownership, RBAC model, and Phase 1 functional baseline. |
| **6.1** | **2026-07-23** | **Consolidation release (Decision Log `SRS-V61-1`), not a scope expansion.** Incorporates already approved and implemented decisions from WP-02 through WP-05 concerning: Application lifecycle; DocumentSubmission lifecycle (including State Machine Addendum / `SM-DOC-1`); reviewer scope and verification (`WP01-E`); Payment attempts and checkout (`PAY-ATTEMPT-1`); and explicit WP-06 handoff ownership. No new product features. Implemented behaviour is not changed merely to tidy historical wording. |

Design Decision: This SRS is conditionally approved at the functional level. It becomes the Approved Build Baseline once: (1) Module, eligibility rules, required documents, certificate rules, assessment policy, and completion policy are owned by CourseVersion, not Course (Section 4); (2) Course vs. CourseVersion field ownership matches Section 4.1's identity/configuration split; (3) the Application/Payment/Enrolment model in Section 8 is implemented as specified - Payment linked to Application, Enrolment created only on Admission; (4) the Role/Permission/UserRole/RolePermission model replaces a single User.role field (Section 3); (5) the additional logical entities in Section 17 (ContentProgress, general AuditLog, Notification/NotificationTemplate, WaitlistEntry, CorporateSeatAllocation, EligibilityRule, CourseDocumentRequirement) are present in the schema; (6) the remaining Section 23 business decisions are confirmed by the academy; (7) the Capacity & NFR Addendum (Section 20.5) is completed; (8) acceptance criteria and a UAT test-scenario companion document are produced.

# 1\. Introduction

## 1.1 Purpose

This SRS translates the approved Product Requirements Document (PRD v1.0) into requirements an engineering team can build against: functional requirements with IDs, a data model, status/state machines, representative APIs, and non-functional requirements. Every major section below references the PRD section it implements, so gaps or disagreements can be traced back to the source document.

**v6.1 consolidation authority (Decision Log `SRS-V61-1`):** Amendments in this version reconcile the functional baseline with approved Decision Log entries, the State Machine Addendum, the Vertical Slice Roadmap, WP-02–WP-05 implementation notes, and AGENTS.md invariants. Where v6.0 narrative conflicted with the approved 11-state Application enum, the Payment attempt model, or DocumentSubmission modelling, this version records the authoritative rule and any remaining open discrepancy without inventing new product behaviour.

## 1.2 Scope

In scope: everything in PRD Section 5.1 (Phase 1 scope). This SRS does not re-litigate scope - where the PRD says a feature is Phase 1, it is specified here; where the PRD marks it Future or Explicitly Excluded (PRD §5.2, §5.3), it is listed in Section 21 of this document for traceability only.

## 1.3 Definitions, Acronyms, and Abbreviations

- LMS - Learning Management System
- PRD - Product Requirements Document (the source business-requirements document for this SRS)
- CME - Continuing Medical Education
- MCQ - Multiple Choice Question
- RBAC - Role-Based Access Control
- Application - A learner's request to join a specific course/batch; carries the eligibility and payment gate. Tracked independently of Enrolment until admission is decided
- Enrolment - An admitted, academically-tracked registration created only once an Application reaches Admitted status - i.e., eligibility approved and payment completed, regardless of which order the admission mode processes them in (Section 8)
- CourseVersion - A specific published configuration of a Course - curriculum, eligibility, documents, fees, certificate rules. Version 1 and Version 2 of the same Course may differ entirely; a Batch delivers one specific version (Section 4.1)
- Admission Mode - The configured sequencing of verification and payment for a course: Mode A (verify-then-pay), Mode B (pay-then-verify), or Mode C (direct, no verification)
- Batch / Cohort - A specific scheduled delivery instance of one CourseVersion, with its own dates, capacity, and faculty
- Role / Permission - A user may hold multiple Roles (e.g., Faculty and Academic Evaluator); each Role grants a set of Permissions. Not a single field on User (Section 3)
- DPDP Act - Digital Personal Data Protection Act, 2023 (India)

## 1.4 Assumptions & Constraints

- India-based operations; INR pricing; data residency consistent with the DPDP Act, 2023 (PRD §24).
- Razorpay and HDFC are both live integrations; only one gateway processes any single payment attempt (PRD §12.1).
- Legal/compliance sign-off on retention periods, refund policy, and grading scheme is a dependency of this SRS, not something engineering should assume or invent (PRD §28, §30).

# 2\. System Architecture (Logical View)

_Implements PRD §1 (Executive Summary), §2 (Vision)_

┌───────────────────────────────────────────┐

│ Client Layer - responsive web (desktop/tablet/mobile) │

│ Guest · Applicant · Learner · Faculty · Reviewer · Admin UIs │

└───────────────────┬─────────────────────────┘

│ HTTPS / TLS 1.2+ / JSON REST

┌───────────────────▼─────────────────────────────────────┐

│ Application Layer │

│ Auth & RBAC · Application & Admission Engine │

│ Course/Batch/Content Engine · Assessment Engine │

│ Certification Engine · Notification Service │

│ Support/Ticketing · Reporting Service │

└──┬──────────┬──────────┬──────────┬──────────┬───────────┘

│ │ │ │ │

┌──▼───┐ ┌────▼─────┐ ┌──▼───────┐ ┌──▼──────┐ ┌──▼──────────┐

│ DB │ │ Document │ │ Video │ │ Payment │ │ Email / │

│(Rel.)│ │ Storage │ │ Storage/ │ │Gateways │ │ Live-Session│

│ │ │(private) │ │Streaming │ │Razorpay │ │ Providers │

│ │ │ │ │ │ │· HDFC │ │(email, Zoom/ │

│ │ │ │ │ │ │ │ │Meet link only)│

└──────┘ └──────────┘ └──────────┘ └─────────┘ └─────────────┘

The Application & Admission Engine is deliberately named separately from the Course/Content Engine: per PRD §11, an Application (pre-admission) and an Enrolment (post-admission, academically tracked) are distinct objects with distinct status machines, not one combined record. This separation is the single most important architectural decision in this document - see Section 8.

# 3\. User Roles & Permissions

_Implements PRD §6_

| **Role**              | **Primary permissions**                                                                                | **Explicitly cannot**                                       |
| --------------------- | ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| Guest                 | Browse catalogue, view eligibility/fees/syllabus, register                                             | Apply, pay, or access content                               |
| Applicant             | Manage profile, upload documents, track application status, pay when eligible                          | Access course content before Enrolment is Active            |
| Active Learner        | Access content, attend sessions, assessments, certificates, invoices, support                          | Review other learners' data or documents                    |
| Faculty               | View assigned courses/batches, content, learner progress, mark attendance, propose question bank items | Approve credential documents or issue refunds               |
| Academic Evaluator    | Enter marks/feedback for non-MCQ items, recommend pass/fail/reassessment                               | Approve credential documents or configure eligibility rules |
| Credential Reviewer   | View/approve/reject documents, request resubmission, view audit history                                | View payment data or alter course pricing                   |
| Course Administrator  | Create/edit courses, batches, modules, eligibility rules, certificate rules, assign faculty            | Approve document verifications or issue refunds             |
| Finance Administrator | View payments, generate invoices, record offline payments, process refunds, reconcile gateways         | View uploaded qualification documents (PRD §6.8, explicit)  |
| Support Executive     | View/respond to support tickets, escalate                                                              | Alter academic records, payments, or documents directly     |
| Super Administrator   | Global configuration, roles, gateways, templates, audit logs                                           | - (all actions logged regardless)                           |

_Design Note: The Finance/Reviewer/Course-Admin split is a segregation-of-duties control, not bureaucracy: the person who can approve a doctor's credentials should not also be the person who can change the price or issue a refund on the same enrolment. Keep these as distinct permission sets even if one person holds multiple roles operationally at launch._

## 3.1 RBAC Model

- **REQ-RBAC-1:** A User is not limited to a single Role. Role is a many-to-many relationship via a UserRole join, so one person can simultaneously be, for example, both Faculty and Academic Evaluator - the table above describes each Role's intended permissions, not an exclusive assignment.
- **REQ-RBAC-2:** Each Role holds a set of Permissions via a RolePermission join; an access-control check evaluates the union of Permissions across every Role the acting user currently holds.
- **REQ-RBAC-3:** Super Admin can create new Roles or adjust Role-Permission mappings through configuration, without a code deployment - the ten roles above are the default set, not a hard ceiling.
- **REQ-RBAC-4 (Finance segregation — consolidated):** Finance Administrator may view normalised Payment attempts and financial metadata. Finance **cannot** access DocumentSubmission content, DocumentSubmission metadata, or signed document URLs — enforced at repository and HTTP layers, not only UI. Role names never bypass permission or object-scope checks. Privileged future Finance mutations (offline mark-paid, reconciliation resolution, refunds) require permission checks, MFA where mandated, audit records, and approved state machines; WP-05 delivers Finance **view-only** payment list/detail only.
- **REQ-RBAC-5 (Reviewer object scope — WP01-E):** Credential Reviewer actions require (a) the relevant permission key and (b) active object scope covering the Application's Course, CourseVersion, and/or Batch. There is **no** Super Admin or role-name bypass of permission or scope. See Section 7.3.1.

# 4\. Course, Batch & Curriculum Model

_Implements PRD §7, §13_

## 4.1 Course Identity vs. Course Version

Course → CourseVersion → Batch/Cohort → Module → Lesson/Content Item → Assessment(s) \[0..n\]

Design Decision: Course and CourseVersion are split by what changes and what doesn't. Course holds only stable identity - course_id, course_code, master_title, product ownership, and a pointer to the current published version. Everything that can legitimately differ between versions - description, curriculum, eligibility rules, required documents, fee, CME credits, validity period, admission mode, assessment policy, certificate rules, and the module structure itself - belongs to CourseVersion, not Course. This matters concretely: Version 1 of the diploma might require only an MBBS and award 20 CME hours at ₹30,000, while Version 2 (after a curriculum revision) requires MBBS plus two years' experience and awards 24 CME hours at ₹35,000 - both versions can coexist, each governing the learners who enrolled under it, with neither able to silently alter the other.

- **REQ-CRS-1:** Course (identity only) holds: course_id, course_code, master_title, product owner, current_published_version_id, and a coarse status (Active/Retired) governing whether the course brand exists at all.
- **REQ-CRS-2:** CourseVersion (all configurable, versioned content) holds: version_number, title, detailed description, learning objectives, intended audience, admission_mode (A/B/C), eligibility rules (via the EligibilityRule entity, Section 7.1), required documents (via the CourseDocumentRequirement entity, Section 7.2), delivery type, duration, validity period, fee/tax, certificate rules, assessment policy, completion policy, faculty, brochure, promo image, and a publishing status: Draft, Under Review, Published, Enrolment Closed, Unpublished, Archived, Cancelled. Only a Published CourseVersion appears in the public catalogue.
- **REQ-CRS-3:** Creating a new Course Version does not alter records, requirements, or certificate rules for learners already enrolled under a prior version - each Enrolment stores a reference to the exact course_version_id it was created under.
- **REQ-CRS-4:** A Batch delivers one specific, published Course Version, not the Course in the abstract - e.g. a January batch may deliver Version 1 while a July batch, created after a curriculum revision, delivers Version 2 of the same course. Batch.course_version_id is the authoritative reference; the parent Course is reachable indirectly through it.
- **REQ-CRS-5:** Module, ContentItem, EligibilityRule, and CourseDocumentRequirement all belong to CourseVersion, not Course (corrected from an earlier draft where Module referenced course_id directly). This is what actually allows Version 1 and Version 2 to have entirely different module structures, eligibility criteria, and document requirements - a Course-level reference would force every version to share one module list.

## 4.2 Batch / Cohort

- **REQ-BATCH-1:** A Batch holds: name, course_version_id (see REQ-CRS-4), start/end dates, enrolment open/close dates, minimum and maximum capacity, assigned faculty, batch-specific fee override, schedule, learner list, waiting list, status, access-expiry date, certificate issue rule.
- **REQ-BATCH-2:** Batch status: Planned, Open for Applications, Open for Enrolment, Full, In Progress, Completed, Cancelled, Archived.
- **REQ-BATCH-3:** If enrolment closes and a batch has not reached minimum capacity, the system shall flag it for Course Administrator decision (proceed / delay start date / cancel with automatic refund) rather than auto-starting or auto-cancelling silently.
- **REQ-BATCH-4:** Waitlist: when a batch is Full, further applicants may join a waiting list in order of application, recorded as a WaitlistEntry (Section 17). When a confirmed seat is vacated (withdrawal, rejection, refund), the system shall notify the next waitlisted applicant and hold the seat for a configurable window (default 48 hours) before offering it to the next person in line.
- **REQ-BATCH-5:** Seat confirmation and waitlist-offer acceptance must be processed atomically (a single database transaction or row lock around the capacity check and the write), so that confirmed Enrolments plus Admitted-but-not-yet-enrolled Applications never exceed Batch.max_capacity - except where an authorised Administrator records an explicit, logged capacity override. Two learners racing for the last seat must never both succeed.

## 4.3 Modules & Content

- **REQ-MOD-1:** A Module holds: title, description, sequence, objectives, estimated duration, mandatory/optional flag, release rule, prerequisite (module-level and, where configured, course-level - e.g. Course B requires prior completion of Course A), completion rule, faculty, and zero, one, or many linked Assessments (a module may have no assessment, a single quiz, or a pre-test and post-test together - see Section 11.1).
- **REQ-MOD-2:** Content item types at launch: video, text lesson, PDF, presentation, audio, downloadable resource, external link, recorded webinar, live session, MCQ assessment, feedback form.
- **REQ-MOD-3:** Release rules: immediate access; sequential (after prior module); N days after enrolment activation; fixed calendar date; manual release by administrator.
- **REQ-MOD-4:** Completion rules are configurable per activity type: video watched to a configured percentage, text/PDF marked read, live session attended, assessment submitted or passed, feedback submitted, or manual faculty mark-complete. Course completion requires all mandatory activities across all mandatory modules to be complete.

# 5\. Course Catalogue & Discovery

_Implements PRD §8_

- **REQ-CAT-1:** Public listing cards show: name, type, duration, delivery format, audience, key eligibility, next batch (if applicable), fee, application/enrolment status, certificate type, faculty, and a call-to-action button.
- **REQ-CAT-2:** Course detail pages show: overview, objectives, syllabus, module summary, faculty, eligibility, required documents, duration/workload, dates, fee & tax, payment policy, refund policy, assessment requirements, certificate rules, FAQ, and the Apply/Enrol action.
- **REQ-CAT-3:** Applications are blocked after the configured closing date unless a Course Administrator grants an explicit, logged exception.

# 6\. Learner Registration & Profile

_Implements PRD §9_

- **REQ-REG-1:** Registration via email + mobile + password, with email verification and mobile OTP verification required before an application can be submitted.
- **REQ-REG-2:** Forgot-password and secure reset flows; explicit acceptance of Terms and Privacy Notice at signup, timestamped and stored.
- **REQ-PROF-1:** Personal profile: legal name, preferred display name, DOB (where required), contact details, address, photo, billing details. The name to appear on certificates is a separately confirmed field, shown back to the learner for confirmation before the first certificate is generated for them.
- **REQ-PROF-2:** Professional profile fields are configurable per Professional Category (not hard-coded to Doctor/Nurse/Allied Staff): profession, primary/additional qualifications, speciality, role, experience, employer, council, registration number/state/issue/expiry date.
- **REQ-PROF-3:** Professional Category is a managed lookup table (Super Admin/Course Admin editable), not an enum baked into application code - new categories (e.g., Physiotherapist, Dietitian) must be addable without a code deployment.

# 7\. Eligibility Rules & Document Verification

_Implements PRD §10_

## 7.1 Eligibility Rule Engine

- **REQ-ELIG-1:** A CourseVersion's eligibility rule is not a single embedded field but one or more EligibilityRule records (Section 17), each testing profession, qualification, speciality, registration validity, experience, employment category, jurisdiction, and/or a specific document's approval - combinable with AND/OR logic (e.g., "Doctor AND valid council registration"). Modelling rules as separate records, not an array on the course, lets each be added, edited, or audited independently.
- **REQ-ELIG-2:** The eligibility rule is evaluated at two points: (a) a soft check at Apply time against the applicant's declared profile, to block obviously ineligible applicants early with a clear message; (b) the authoritative check, which is the Credential Reviewer's document approval - self-declared profile data is never sufficient on its own for a restricted course.

## 7.2 Required Document Configuration

- **REQ-DOC-1:** Per CourseVersion, Course Administrator configures each required document as a CourseDocumentRequirement record (Section 17), not an embedded array: name, description, mandatory/optional, accepted file types, max size, single/multiple files, front-and-back requirement, issue-date/expiry-date/registration-number/issuing-authority fields, reuse eligibility, and reviewer instructions. Application submission and review always use the exact CourseVersion referenced by the Application; client input cannot substitute another CourseVersion, requirement set, or fee.
- **REQ-DOC-2 (business `status`):** DocumentSubmission business statuses (repository constants / snake_case persistence): `uploaded`, `under_review`, `approved`, `rejected`, `resubmission_requested`, `expired`, `superseded`, `failed_security_scan`. Display labels: Uploaded, Under Review, Approved, Rejected, Resubmission Requested, Expired, Superseded, Failed Security Scan. **Not Uploaded** remains a conceptual UI state when no current submission row exists for a requirement; persisted rows never use a `not_uploaded` status value. Do **not** add `Scan Pending` as a business status. The applicant sees live status for every required document.
- **REQ-DOC-2a (malware `scan_status` — separate dimension):** `scan_status` is independent of business `status` (Decision Log `SM-DOC-1` / State Machine Addendum). Exact values: `not_applicable`, `pending`, `clean`, `failed`. While a scan is outstanding: business `status = uploaded` and `scan_status = pending`. A **stuck scan** is `scan_status = pending` beyond the configured SLA — an operational condition with alert/retry handling — **not** a separate enum value. Stuck or pending scans never enter the reviewer queue and are never treated as clean.
- **REQ-DOC-3:** Uploaded files are malware-scanned asynchronously before entering the review queue; `scan_status = failed` drives business transition to `failed_security_scan` and the file is quarantined, never shown to a reviewer (no signed URL to malware-positive objects).
- **REQ-DOC-4:** Documents are stored in private object storage; reviewers access clean current (and, where permitted, historical clean) versions via short-lived signed URLs (10-15 minute expiry) with in-browser zoom, never a raw downloadable link by default. Finance never receives document metadata or signed URLs.
- **REQ-DOC-6 (current-row and history model — `SM-DOC-1`):** Resubmission (including retry after Failed Security Scan) **creates a new DocumentSubmission row**. Historical submissions are immutable. The previous current row is marked `superseded` and `current_marker = NULL`. The new current row uses `current_marker = 1`. Database rule: `UNIQUE(application_id, requirement_id, current_marker)` (MySQL allows multiple NULL markers for history; at most one row may hold `current_marker = 1` per application+requirement). Resubmission runs in one transaction: `SELECT … FOR UPDATE` on the current row → supersede + clear marker → insert new current (`uploaded` / `pending`) → audit + outbox → commit. Stale scan workers must not update a superseded row or a replacement submission that is no longer current. Application "documents complete / under review" logic uses `current_marker = 1` only.

## 7.3 Reviewer Workflow

- **REQ-REV-1:** Reviewer can approve, reject, or request resubmission on a **current** DocumentSubmission that is `status = under_review` and `scan_status = clean`. Historical / superseded rows are read-only. Rejection and resubmission require a reason from the fixed allow-list (server-validated): `blurry_illegible`, `wrong_document`, `incomplete`, `expired_registration`, `name_mismatch`, `qualification_ineligible`, `registration_number_not_visible`, `issuing_authority_not_identifiable`, `other`. Learner-visible messaging is length-bounded and sanitized. Private internal notes are stored only in VerificationAuditLog — never in general `audit_log` payloads and never shown to the learner. Reviewers may view full submission history (prior versions) including prior reviewer and date, subject to permission and object scope; Failed Security Scan file content remains quarantined.
- **REQ-REV-2:** Every review action is written to an append-only `verification_audit_log` (reviewer, action, reason, notes, timestamp) — never edited or deleted, including by Super Admin. This log is **separate** from the technical `audit_log`.
- **REQ-REV-3:** A configurable maximum resubmission count (default 5) triggers manual Admin intervention rather than further self-service resubmission; this is an operational flag, not a hard database constraint, so it can be overridden.
- **REQ-REV-4 (Application verification outcomes — Mode A / WP-04):** Application transitions owned by reviewer verification (exact repository snake_case): `under_review → payment_pending` (Mode A approval — **no Payment row created at approval**); `under_review → rejected` (with reason code); `under_review → resubmission_requested` (correction request identifying specific requirements); `resubmission_requested → under_review` (system/learner correction resubmit complete). Document business transitions: `under_review → approved` | `rejected` | `resubmission_requested`. Application approval to Payment Pending requires every mandatory current document `approved` and `clean`; no current rejected / resubmission_requested / pending-scan mandatory evidence. Learner may replace only affected documents; unrelated Application fields remain locked. Decision and correction history is append-only.
- **REQ-REV-5 (dual assignment model — WP01-E):** See Section 7.3.1.

### 7.3.1 Reviewer scope and queue claim (WP01-E)

Two complementary assignment mechanisms:

1. **`reviewer_scope_assignments`** — object scope targeting Course, CourseVersion, and/or Batch. Active assignments combine as a **union**. Permission **and** object scope are both required for reviewer actions. `include_future_versions` defaults to **false** (Course-level assignments do not automatically include future CourseVersions unless explicitly enabled). Assignments support effective dates, revocation history, and creator/revoker identity, with audit events.
2. **`application_review_assignments`** — queue claim: at most one active claim per Application (`active_marker = 1`); historical claims preserved; concurrent claims produce one winner (row lock + unique constraint).

No Super Admin or role-name bypass exists. Reviewer actions require permission, scope, and an active claim where the action policy requires one. Finance has no document-review access.

## 7.4 Reuse of Verified Credentials

- **REQ-DOC-5:** A previously approved document may be reused for a new application only if: it has not expired, the new course accepts that document type, and the course has not marked re-verification as mandatory. Reuse is still logged as a distinct verification-audit event referencing the original approval.

# 8\. Application, Admission Modes, and Enrolment

_Implements PRD §11 - the highest-risk section: get explicit Product/Finance/Compliance sign-off before build._

## 8.1 Application vs. Enrolment - a deliberate split

Design Decision: Application and Enrolment are two separate records with two separate status machines, per PRD §11.2 and §11.4 - and, per this revision, Payment belongs to the Application, not the Enrolment. An Application tracks a learner's entire pre-admission journey: profile, documents, and payment, for one course/batch. Enrolment is created only once the Application reaches Admitted status - meaning eligibility is approved AND payment has succeeded, in whichever order the admission mode processes them. This single change resolves three problems together: (a) it removes the awkwardness of Payment belonging to an Enrolment that might not yet reflect a real admission decision; (b) a Mode B rejection after payment now simply closes out the Application and its linked Payment/Refund - there is no Enrolment to unwind, because none was ever created; (c) it removes the ambiguity of whether an Enrolment that exists before a batch starts should read as 'Active' - Enrolment now begins as Scheduled if the batch start date is in the future, or Active immediately for self-paced courses and batches already under way, so 'Active' consistently means both admitted and currently accessible.

- **REQ-APP-1 (authoritative 11-status enum):** Application statuses (display / repository snake_case): Draft (`draft`), Submitted (`submitted`), Documents Incomplete (`documents_incomplete`), Under Review (`under_review`), Resubmission Requested (`resubmission_requested`), Payment Pending (`payment_pending`), Awaiting Verification (`awaiting_verification`), Admitted (`admitted`), Rejected (`rejected`), Withdrawn (`withdrawn`), Expired (`expired`). **Cancelled is not part of this enum** (see Open Discrepancy OD-APP-CANCELLED in Section 23.1). Payment's own detailed status (Section 9.1 / 18.4) lives on the Payment entity, not duplicated as extra Application states.
- **REQ-APP-2 (Draft construction — `APP-DRAFT-1`):** Creating an Application with status Draft is an **entity-factory / constructor** operation at persistence time. It is **not** a state-machine transition from a prior Application status. After persistence, **every** status change must go through `ApplicationStateMachine`. Repositories must **not** expose general `updateStatus()` methods.
- **REQ-APP-3 (CourseVersion binding):** At Draft creation the Application records the exact `course_version_id` (and `batch_id`) selected. CourseVersion commercial, eligibility, and document-requirement configuration is immutable once published or referenced per approved locking rules (`locked_at` / `locked_reason`). Application submission and review always use that exact referenced CourseVersion; client input cannot substitute another CourseVersion, requirement, or fee.
- **REQ-APP-4 (edit and immutability rules):** Learner edits are permitted only in states explicitly authorized (Draft construction/completion; correction flows while Resubmission Requested for affected documents only). Submitted Applications are immutable except for approved correction flows. Unrelated Application fields remain locked during correction.
- **REQ-APP-5 (Mode A submission preconditions — WP-03):** Before Draft → Submitted the system requires: (Account) active account; verified email; verified mobile. (Application/profile) required Application fields complete; explicit profile required fields complete; required declaration/version accepted. (Documents) every mandatory CourseVersion requirement has exactly one current submission (`current_marker = 1`) with `scan_status = clean` and acceptable business status in {`uploaded`, `under_review`, `approved`}; no missing, pending, failed, infected/stuck, or superseded current evidence for mandatory requirements. Optional requirements do not block submission. In the implemented Mode A flow, **Draft → Submitted → Under Review** occurs atomically in one service transaction when document preconditions are met (system edge Submitted → Under Review).
- **REQ-APP-6 (vertical-slice ownership):** Payment Pending is the WP-05 starting Application state. Application → Admitted (and Enrolment creation) belongs to **WP-06** and is not claimed as implemented by WP-02–WP-05.
- **REQ-ENR-1:** Enrolment is created only when an Application reaches Admitted. Enrolment lifecycle status (access dimension): Scheduled, Active, Suspended, Withdrawn, Cancelled, Refunded, Access Expired. Scheduled applies only to cohort courses admitted ahead of their batch's start date, and transitions to Active automatically on that date; self-paced admission goes directly to Active.
- **REQ-ENR-2:** Enrolment academic status (progress/outcome dimension - a separate field, populated once lifecycle_status = Active): Not Started, In Progress, Academically Completed, Passed, Not Passed. Splitting lifecycle and academic status into two fields avoids the ambiguity of whether 'Passed' replaces or coexists with 'Active', and keeps a Suspended or Access-Expired learner's academic record intact rather than overwritten.

## 8.2 Admission Mode A - Verification before Payment

Recommended default for restricted/high-value programmes (PRD §11.1, §29.3).

Register → Apply → Upload documents → Reviewer decision

├─ Approved → Application.status = Payment Pending → Pay (Payment.application_id set; multiple attempts allowed per Section 9.1)

│ → Payment successful (**WP-06 webhook/reconcile — authoritative**) → Application.status = Admitted → Enrolment created

└─ Rejected → Application.status = Rejected → resubmit (§7.3) or withdraw

**Mode A implementation note (WP-02–WP-05):** Draft factory (WP-02) → document upload + atomic Draft→Submitted→Under Review (WP-03) → reviewer verification to Payment Pending (WP-04) → checkout creates Payment attempt Created→Pending (WP-05). Browser return is informational only. Admitted / Enrolment / capacity are WP-06.

## 8.3 Admission Mode B - Payment before Verification

Configurable for selected low-risk courses only (PRD §11.1); the course-level configuration choosing this mode should require explicit Course Administrator sign-off given the refund exposure named in PRD §29.3.

Register → Apply → Pay (Payment.application_id set) → Application.status = Awaiting Verification

→ Reviewer decision

├─ Approved → Application.status = Admitted → Enrolment created

└─ Rejected → Application.status = Rejected → Refund processed against the

Application-linked Payment (REQ-REFUND-2) - no Enrolment ever

existed, so there is no academic record to unwind, only a financial one

## 8.4 Admission Mode C - Direct Enrolment

For unrestricted courses with no required documents.

Register → Apply → Pay (Payment.application_id set) → Application.status = Admitted → Enrolment created

- **REQ-ADM-1:** Admission Mode is a per-CourseVersion configuration field (A, B, or C), set by Course Administrator at course creation and changeable only for future batches, never retroactively for learners already mid-flow.
- **REQ-ADM-2:** Regardless of mode, designation/profession eligibility (REQ-ELIG-2a) is checked at Apply time, before any payment step is even offered - this holds even in Mode B.

## 8.5 Enrolment Confirmation

- **REQ-ENR-3:** On reaching Active (or Scheduled, for a future-dated batch), the learner receives: course name, batch, start/access/end dates, payment receipt, invoice, login link, and support contact.

# 9\. Payments, Invoicing, Refunds & Corporate Enrolment

_Implements PRD §12, §23_

## 9.1 Payment Processing

- **REQ-PAY-1:** Razorpay and HDFC are both supported; only one gateway processes a given payment attempt; the active gateway is selectable globally or per course, and a change affects new attempts only (does not alter history). The Mode A vertical slice uses Razorpay only (HDFC deferred — Decision Log `VS-SCOPE-1`).
- **REQ-PAY-2:** Payment methods, subject to gateway support: UPI, debit/credit card (RuPay/Visa/Mastercard), net banking.
- **REQ-PAY-3:** Access/Enrolment activation is driven only by a trusted server-side webhook confirmation - never by the client browser reaching a 'success' page - to eliminate the class of bug where a closed tab leaves a payment ambiguous. Browser checkout return is informational ("Confirming payment…") only: it **never** marks Payment Successful and **never** transitions Application.
- **REQ-PAY-4:** If webhook confirmation has not arrived within 8 seconds of checkout completion, the system polls the gateway directly; unresolved after 30 minutes, the payment is flagged for manual Finance reconciliation rather than silently retried indefinitely. **WP-06 ownership:** durable webhook receipt, reconciliation worker/cron, and authoritative Payment → Successful are implemented in WP-06 — not WP-05.
- **REQ-PAY-5:** Duplicate webhook callbacks and duplicate-enrolment attempts for the same order are detected and rejected idempotently. At most one accepted successful payable outcome is permitted (`successful_marker` enforcement in WP-06). Additional captured payments must enter Reconciliation Pending. Duplicate success must never create a second Enrolment.
- **REQ-PAY-6 (ownership):** Payment belongs to Application (`application_id` NOT NULL / mandatory), not to Enrolment. It carries a separate, nullable `enrolment_id`, populated only once the Application reaches Admitted and an Enrolment is created. This keeps Mode B rejection, failed payments, abandoned Applications, and refunds-without-enrolment straightforward.
- **REQ-PAY-6a (attempt model — `PAY-ATTEMPT-1`):** An Application may have **multiple** Payment records representing separate payment attempts. Historical attempts are preserved. Only one **in-flight** attempt may exist at a time (in-flight statuses: Created / Pending — repository `created`, `pending`). A new attempt is permitted only after the prior attempt reaches Failed, Cancelled, or Expired (`failed`, `cancelled`, `expired`). Successful or Reconciliation Pending prevents a new automatic attempt. Wording that implied "at most one Payment-chain" in v6.0 is superseded by this attempt model.
- **REQ-PAY-7 (exactly 10 statuses):** Payment status display labels and repository snake_case: Created (`created`), Pending (`pending`), Successful (`successful`), Failed (`failed`), Cancelled (`cancelled`), Expired (`expired`), Reconciliation Pending (`reconciliation_pending`), Refunded (`refunded`), Partially Refunded (`partially_refunded`), Disputed (`disputed`). **Do not add** Authorized, Captured, or Processing as Payment domain statuses. Architecture references to "processing" are descriptive cron wording, not a Payment domain status. Transition matrix: Section 18.4 / `PaymentStateMachine`.
- **REQ-PAY-9 (immutable amount snapshot — WP-05):** Each Payment stores an immutable commercial snapshot at initiation: base fee in integer minor units; GST in integer minor units; total payable in integer minor units; currency; CourseVersion id; Batch id; whether a Batch `fee_override` was used; applicable GST rate. Values are derived server-side from the locked Application, Batch, and exact CourseVersion (`fee_override` takes precedence where present). No client-provided amount, currency, or GST is trusted. Total equals base plus GST. All arithmetic uses integer minor units; floating-point payment calculations are forbidden.
- **REQ-PAY-10 (WP-05 checkout flow):** (1) Learner owns a `payment_pending` Application. (2) Local Payment is created as Created within a DB transaction. (3) Audit / payment_status_history / outbox are committed. (4) Razorpay order creation occurs **outside** the DB transaction. (5) Provider order is bound in a second transaction. (6) Payment transitions Created → Pending. (7)–(9) Browser return is informational only — never Successful, never Application transition. **Orphan recovery:** an unbound Created attempt is resumed; the deterministic gateway idempotency key is reused; no duplicate local attempt is created; provider amount and currency are revalidated before binding.
- **REQ-PAY-11 (WP-06 handoff — ownership boundary):** WP-06 owns: Razorpay webhook ingress; raw-body signature verification; durable webhook event receipt and idempotency; reconciliation worker/cron; authoritative Payment → Successful; one accepted-success enforcement using `successful_marker`; duplicate capture → Reconciliation Pending; Application Payment Pending → Admitted; capacity enforcement; Enrolment creation; ensuring no second Enrolment is created. **WP-05 owns none of those mutations.**

## 9.2 Pricing

- **REQ-PRICE-1:** A course or batch may define: base fee, GST, discount, coupon, scholarship, complimentary enrolment, corporate sponsorship, and a batch-specific fee override. For Mode A checkout (WP-05), the payable snapshot uses Batch `fee_override` where present, otherwise CourseVersion `standard_fee`, plus CourseVersion `gst_rate` and `currency`, computed in integer minor units (REQ-PAY-9).
- **REQ-PRICE-2:** Coupon codes are single-use or capped-use (configurable), time-bound, and their redemption is logged against the applying learner and course.

## 9.3 GST Invoicing

- **REQ-INV-1:** Every successful payment generates a GST-compliant invoice: academy legal name/address/GSTIN, invoice number/date, learner or organisation name and billing address, customer GSTIN where applicable, course description, taxable amount, GST, total, and payment reference.
- **REQ-INV-2:** Invoice status: Draft, Issued, Cancelled, Credit Note Issued.

## 9.4 Refunds

- **REQ-REFUND-1:** Refund rules are configurable per course: full, partial, refund-after-verification-rejection, refund-after-course-cancellation, and administrative deduction where policy permits. The exact percentages/windows are a business decision (PRD Open Question) - this SRS defines the mechanism, not the numbers.
- **REQ-REFUND-2:** A Mode B rejection (8.3) always resolves to one of: full refund, transfer to another course, or a documented exception, processed against the Payment linked to the Application. Because no Enrolment was ever created on this path, there is no academic record to unwind - only the financial transaction needs closing out, and it never remains indefinitely unresolved.
- **REQ-REFUND-3:** Refund status: Requested, Processing, Completed, Failed, Cancelled, Denied. Failed covers a gateway refund that errors out after entering Processing - without this state, such a refund would sit indefinitely with no way to distinguish it from one still genuinely in flight. Credit notes are generated where GST rules require them. Every refund event notifies the learner.

## 9.5 Offline Payments

- **REQ-PAY-8:** Finance Administrator can record approved offline payments (bank transfer, institutional payment) with supporting reference and a mandatory audit trail entry. **Not part of WP-05:** WP-05 Finance access is view-only over normalised Payment attempts; no manual mark-paid or reconciliation action ships in WP-05.

## 9.6 Corporate / Sponsored Enrolment

- **REQ-CORP-1:** A Corporate Account holds organisation details, contact, and agreement/PO reference. Each seat package purchased against a batch is its own CorporateSeatAllocation record (Section 17) - seats purchased, seats consumed, price, invoice reference, and seat expiry - rather than an attribute folded into CorporateAccount or Voucher alone.
- **REQ-CORP-2:** Sponsored seats are redeemed via a voucher/invitation code; redemption still routes through the full eligibility and document-verification flow for that course - sponsorship pays the fee, it does not waive eligibility (PRD §23, explicit).
- **REQ-CORP-3:** Corporate contacts can view seat utilisation and (subject to learner consent/privacy rules) progress summaries for their own organisation's sponsored seats only.

# 10\. Live Sessions & Attendance

_Implements PRD §14_

- **REQ-LIVE-1:** A Live Session is created against a course/batch with date, time, duration, faculty, meeting link, and preparation material; reminders are sent ahead of the session.
- **REQ-LIVE-2:** Attendance is recorded manually at launch, with spreadsheet import supported; automatic sync with Zoom/Meet/Teams is a Phase 2 item (PRD §5.2).
- **REQ-LIVE-3:** A course may define a minimum attendance percentage as a configurable prerequisite for Certificate of Participation and/or Completion eligibility.
- **REQ-LIVE-4:** Sessions can be rescheduled or cancelled by Course Administrator or assigned Faculty; affected learners are notified automatically, and a recording can be attached afterward as on-demand content.

# 11\. Assessment Engine & Grading

_Implements PRD §15_

## 11.1 Module-Assessment Relationship

- **REQ-EVAL-0:** A Module has zero, one, or many Assessments (corrected from an earlier one-to-one assumption - a module may need a formative pre-test and a summative post-test together, or a participation-only quiz alongside a graded one). Each Assessment individually declares: mandatory or optional, formative or graded, its weight/contribution to the module or course final grade (if graded), whether it counts toward the Participation requirement (Section 12), and its sequence relative to sibling assessments in the same module.

## 11.2 Assessment Configuration

- **REQ-EVAL-1:** MCQ assessments at launch; architecture keeps question-type extensible (multiple-response, true/false, case-based, short/descriptive answer, file-upload assignment) for Phase 2 without a schema rewrite - a question_type field on the Question entity, not a hard-coded MCQ-only table.
- **REQ-EVAL-2:** Per assessment, Course Administrator configures: linked module, question bank, question count per attempt, marks per question, pass percentage, time limit, open/close dates, max attempts, cooldown between attempts, question/option randomisation, free vs. sequential navigation, whether unanswered questions are allowed, auto-submit on timeout, immediate or delayed result display, whether correct answers/explanations are shown, and which attempt counts (highest/latest/average).

## 11.3 Attempt Continuity & Exhaustion

- **REQ-EVAL-3:** Responses autosave; a temporary connectivity drop preserves progress; only one active attempt per learner per assessment at a time; attempt start/submit/timeout events and any admin override are all recorded.
- **REQ-EVAL-4:** On exhausting all permitted attempts, the assessment locks and the learner is notified. The learner may submit a reassessment request; only an authorised Faculty/Academic Evaluator/Admin can grant one additional attempt, and the grant is written to the audit trail with a reason.

## 11.4 Assessment Responses

- **REQ-EVAL-5:** Every question answered within an attempt is stored as its own AssessmentResponse record (Section 17) - attempt, question, the exact question version shown, the option(s) selected, correct/incorrect, marks awarded, and the timestamp answered. Storing only a total score on AssessmentAttempt is not sufficient: without a response-level record, the system cannot recalculate a disputed score, show a learner their answer review, support partial marking, or analyse which specific question is causing failures across a cohort.
- **REQ-EVAL-6:** A learner may flag a question during an attempt for later review (e.g., "come back to this"); this flag is stored per response and surfaced back to the learner within the same attempt, not persisted as a permanent dispute flag.
- **REQ-EVAL-7:** AssessmentResponse stores type-appropriate answer data alongside selected_option_id(s): text_response, numeric_response, file_ref, and structured_response_json. Only the MCQ-relevant fields are populated in Phase 1; the others stay null - but their presence now means Phase 2 question types (descriptive, numeric, file-upload, case-based) can be added without another schema migration, consistent with the extensibility already claimed for question_type (REQ-EVAL-1).

## 11.5 Passing Models (configurable per course)

- **REQ-GRADE-1:** Model A - every mandatory module assessment must individually meet its passing threshold.
- **REQ-GRADE-2:** Model B - an aggregate/weighted final score must meet the course's configured threshold, even if one module score is individually lower.
- **REQ-GRADE-3:** Model C - combined: specified critical assessments must each be passed, and the overall aggregate must also meet its threshold.
- **REQ-GRADE-4:** Where weighted, Grade_final = Σ(Score_i × Weight_i) / Σ(Weight_i), computed using the course's configured attempt rule (highest / latest / average) for each module.
- **REQ-GRADE-5:** Grade bands (e.g., Distinction/A/B/Not Passed and their percentage cut-offs) are configurable per course; the academy approves the actual scheme per programme before launch (PRD §15.7, open question).
- **REQ-GRADE-6:** Only specifically authorised academic roles may manually adjust a result; every adjustment records original value, revised value, reason, authorising user, and timestamp.

# 12\. Certificates

_Implements PRD §16_

- **REQ-CERT-1:** Certificate of Participation issues automatically when the learner completes all mandatory learning activities and any configured attendance requirement - assessment performance does not block it unless the course specifically defines assessment submission (not passing) as a mandatory participation activity. Completion of each activity is read from its ContentProgress record (Section 17), not inferred from access logs.
- **REQ-CERT-2:** Certificate of Completion issues automatically when the learner additionally meets the course's passing model (Section 11.5) and displays the final grade/classification/percentage per course configuration.
- **REQ-CERT-3:** Certificates display: academy name/logo, learner's confirmed certificate name, course title, batch (where relevant), duration/credit hours, completion date, grade (where applicable), certificate number, QR code, authorised signatures, issue date, and public verification URL.
- **REQ-CERT-4:** Public verification (by QR or certificate number) returns only: validity, learner name, course, certificate type, grade (where applicable), issue date, and revoked status - never contact details, to prevent the verification endpoint becoming a personal-data leak.
- **REQ-CERT-5:** Authorised Admins can correct a learner's name, reissue, or revoke a certificate; every action is reasoned and dated, and prior issuance history is preserved rather than overwritten. One Enrolment may hold both a Participation and a Completion certificate.
- **REQ-CERT-6:** A course version change (Section 4.1) does not retroactively alter certificate rules for learners already enrolled under the prior version.

# 13\. Dashboards

_Implements PRD §17-19_

## 13.1 Learner Dashboard

- **REQ-DASH-LRN-1:** Current courses, application statuses, document statuses, upcoming sessions, progress, next recommended activity, assessments due/results, access-expiry date, certificates, invoices, notifications, support requests, and a resume-last-activity shortcut.

## 13.2 Faculty Dashboard

- **REQ-DASH-FAC-1:** Assigned courses/batches, upcoming sessions, learner lists, attendance, progress summaries, assessment performance, learners needing intervention, announcements, queries - edit permissions scoped to assigned role.

## 13.3 Administrative Dashboards

- **REQ-DASH-VER-1:** Applications/Verification dashboard: pending applications and documents, resubmissions, rejections, applications nearing expiry, average turnaround.
- **REQ-DASH-OPS-1:** Course Operations dashboard: active/upcoming courses and batches, learner counts, capacity utilisation, delayed modules, upcoming sessions.
- **REQ-DASH-ACAD-1:** Academic dashboard: progress, module completion, attempts, pass/fail rates, at-risk learners, certificates pending issuance.
- **REQ-DASH-FIN-1:** Finance dashboard: successful/pending/failed payments, refunds, revenue by course/batch, gateway reconciliation, offline payments, outstanding institutional invoices.

# 14\. Notifications

_Implements PRD §20_

- **REQ-NOTIF-1:** Email and in-application notifications, with editable templates, are triggered at minimum by: registration/verification (OTP), application saved/submitted/documents missing, document approval/rejection/resubmission requested, application approval/rejection, waitlist seat offered (added to close a gap in the source PRD's list), payment request/success/failure, enrolment confirmation/course start/new module release, live session reminder/reschedule, assessment opening/deadline/result/attempt-limit reached, course access expiring, certificate issuance, refund initiation/completion, and support ticket response.
- **REQ-NOTIF-2:** Every notification sent is persisted as a Notification record (recipient, template used, channel, delivery status, sent_at) - not fired and forgotten - and its content comes from an editable NotificationTemplate record per event type, so Admin can change wording without a deployment.

# 15\. Learner Support & Grievances

_Implements PRD §21_

- **REQ-SUP-1:** Ticket categories: Technical, Payment, Refund, Document Verification, Course Access, Academic Query, Assessment Issue, Certificate Correction, Other.
- **REQ-SUP-2:** Each ticket holds: number, learner, course/enrolment reference, category, description, attachments, status (Open, Assigned, Awaiting Learner, Escalated, Resolved, Closed), assigned team, response thread, resolution, closure date, sla_due_at, and escalation_reason (populated only if escalated).
- **REQ-SUP-3:** Internal notes on a ticket are never visible to the learner; only responses explicitly marked learner-visible appear in the learner's own view of the ticket.
- **REQ-SUP-4:** A ticket may be reassigned between team members, and reopened after Resolved/Closed within a configurable window; both actions are logged.
- **REQ-SUP-5:** Attachment visibility follows the same rule as notes - an attachment added internally is not exposed to the learner unless explicitly shared.
- **REQ-SUP-6:** Each ticket category carries a configurable SLA target; a breach surfaces on the relevant administrative dashboard (REQ-DASH-VER-1 / REQ-DASH-ACAD-1 / REQ-DASH-FIN-1, as the category dictates) rather than being visible only in a report.
- **REQ-SUP-7:** Ticket assignment and response events trigger notifications per Section 14 (REQ-NOTIF-1).

# 16\. Reporting & Exports

_Implements PRD §22_

- **REQ-RPT-1:** Applications & verification: by status, pending docs, rejection reasons, turnaround time, reviewer activity, expiring registrations.
- **REQ-RPT-2:** Enrolment: by course/batch/profession, active learners, withdrawals, suspensions, completion rates.
- **REQ-RPT-3:** Academic: progress, module completion, scores, attempts, pass/fail rates, grade distribution, at-risk learners, attendance.
- **REQ-RPT-4:** Finance: payments, failures, refunds, discounts, GST, revenue by course/batch, gateway reconciliation, offline payments, institutional invoices.
- **REQ-RPT-5:** Certification: participation/completion counts, reissued, revoked, verification activity.

All reports export to CSV or Excel.

# 17\. Data Model (Core Entities)

A logical model - engineering may choose the physical schema, but every entity and relationship below should be represented and every enum kept in a configuration table, not hard-coded, wherever the PRD marks it configurable. Organised into five groups for readability; cross-group relationships are called out in the Relationships column.

## 17.1 Identity & Access

| **Entity**           | **Key Fields**                                                            | **Relationships**                                                                                            |
| -------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| User                 | user_id, name, email, phone, password_hash                                | Has many UserRoles (not a single role field - see REQ-RBAC-1); has one LearnerProfile; has many Applications |
| Role                 | role_id, name                                                             | Has many RolePermissions; has many UserRoles                                                                 |
| Permission           | permission_id, name, description                                          | Referenced by RolePermission                                                                                 |
| UserRole             | user_id, role_id                                                          | Join table - a User may hold multiple Roles                                                                  |
| RolePermission       | role_id, permission_id                                                    | Join table - a Role may hold multiple Permissions                                                            |
| ProfessionalCategory | category_id, name, required_profile_fields\[\]                            | Referenced by LearnerProfile and EligibilityRule                                                             |
| LearnerProfile       | user_id, personal fields, professional fields, certificate_name_confirmed | Belongs to User                                                                                              |

## 17.2 Course & Curriculum

| **Entity**                | **Key Fields**                                                                                                                                                                                                            | **Relationships**                                                                                                                                                      |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Course                    | course_id, course_code, master_title, product_owner, current_published_version_id, status (Active/Retired)                                                                                                                | Stable identity only (Section 4.1); has many CourseVersions                                                                                                            |
| CourseVersion             | version_id, course_id, version_number, title, description, admission_mode (A/B/C), cme_credits, standard_fee, gst, validity_period, status (Draft/Under Review/Published/Enrolment Closed/Unpublished/Archived/Cancelled) | Belongs to Course; has many Modules, EligibilityRules, CourseDocumentRequirements; referenced by Batch and Enrolment                                                   |
| EligibilityRule           | rule_id, course_version_id, field, operator, value, logic_group (AND/OR)                                                                                                                                                  | Belongs to CourseVersion - a standalone record, not an embedded array (Section 7.1)                                                                                    |
| CourseDocumentRequirement | requirement_id, course_version_id, document_name, description, mandatory_flag, accepted_file_types, max_size, single_or_multiple, reuse_allowed, reviewer_instructions                                                    | Belongs to CourseVersion - a standalone record, not an embedded array (Section 7.2)                                                                                    |
| Batch                     | batch_id, course_version_id, name, dates, min/max capacity, faculty\[\], fee_override, schedule, status                                                                                                                   | Belongs to CourseVersion (REQ-CRS-4); has many Applications/Enrolments; has WaitlistEntries                                                                            |
| WaitlistEntry             | entry_id, batch_id, application_id, position, offered_at, offer_expires_at, status (Waiting/Offered/Accepted/Expired)                                                                                                     | Belongs to Batch and Application                                                                                                                                       |
| Module                    | module_id, course_version_id, sequence, mandatory_flag, release_rule, prerequisite_ref, completion_rule                                                                                                                   | Belongs to CourseVersion (REQ-CRS-5, corrected from Course); has ContentItems; has zero or many Assessments                                                            |
| ContentItem               | content_id, module_id, type, source_url, duration                                                                                                                                                                         | Belongs to Module                                                                                                                                                      |
| ContentProgress           | progress_id, enrolment_id, content_id, resume_position, watch_percentage, completion_status, first_accessed_at, last_accessed_at, completed_at, manual_override_flag, completion_source                                   | Belongs to Enrolment and ContentItem - required to reliably drive Certificate of Participation (REQ-CERT-1); without it, progress and participation cannot be computed |

## 17.3 Application, Payment & Enrolment

| **Entity**              | **Key Fields**                                                                                                       | **Relationships**                                                                                                                                |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| Application             | application_id, application_number, user_id, course_version_id, batch_id (nullable for self-paced), status, state_version, declaration_accepted_version/at, submitted_at | Has many DocumentSubmissions; has many Payment attempts (`PAY-ATTEMPT-1`); leads to at most one Enrolment |
| DocumentSubmission      | document_submission_id, application_id, requirement_id, object_key, status, scan_status, current_marker, rejection_reason_code, learner_visible_message, row_version | Belongs to Application; references CourseDocumentRequirement; current row `current_marker=1`; history NULL; has many VerificationAuditLog entries |
| VerificationAuditLog    | audit_id, document_id, reviewer_id, action, reason, notes, timestamp                                                 | Belongs to DocumentSubmission (append-only); distinct from technical AuditLog                                                                 |
| ReviewerScopeAssignment | assignment_id, reviewer_user_id, scope_type (Course/CourseVersion/Batch), scope_id, include_future_versions, effective dates, revoked_at, created_by, revoked_by | Union of active scopes; permission + scope required (WP01-E)                                                                              |
| ApplicationReviewAssignment | assignment_id, application_id, reviewer_user_id, active_marker, claimed_at, released_at                            | At most one active claim per Application; historical claims preserved                                                                     |
| Payment                 | payment_id, application_id (NOT NULL), enrolment_id (nullable until Admitted), attempt_number, immutable minor-unit snapshot (base/gst/total), currency, gst_rate, course_version_id, batch_id, fee_override flag, gateway, provider_order_id, idempotency_key, status, successful_marker, transaction_ref | Belongs to Application (REQ-PAY-6/6a); many attempts per Application; may have Refund; produces Invoice; PaymentStatusHistory append-only |
| PaymentStatusHistory    | history_id, payment_id, from_status, to_status, actor, reason, timestamp                                             | Append-only Payment history                                                                                                               |
| Invoice                 | invoice_id, payment_id, status, gstin, taxable_amount, gst_amount, total                                             | Belongs to Payment                                                                                                                               |
| Refund                  | refund_id, payment_id, reason, amount, status, processed_by                                                          | Belongs to Payment                                                                                                                               |
| Coupon                  | coupon_id, code, discount_rule, max_uses, valid_from/to                                                              | Referenced by Payment                                                                                                                            |
| CorporateAccount        | corp_id, organisation_name, contact, agreement_ref                                                                   | Has many CorporateSeatAllocations                                                                                                                |
| CorporateSeatAllocation | allocation_id, corp_id, batch_id, seats_purchased, seats_consumed, price, invoice_ref, seat_expiry                   | Belongs to CorporateAccount and Batch; has many Vouchers                                                                                         |
| Voucher                 | voucher_id, allocation_id, code, status, redeemed_by_application_id                                                  | Belongs to CorporateSeatAllocation                                                                                                               |
| Enrolment               | enrolment_id, application_id, course_version_id, batch_id, lifecycle_status, academic_status, access_expires_at      | Belongs to Application; created only on Admission (Section 8); has Payments (via Application), AssessmentAttempts, ContentProgress, Certificates |

## 17.4 Assessment

| **Entity**         | **Key Fields**                                                                                                                                                                                                       | **Relationships**                                                                                                                                                                   |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Assessment         | assessment_id, module_id, sequence, mandatory_flag, formative_or_graded, counts_toward_participation, pool_size, questions_per_attempt, pass_threshold, attempt_cap, cooldown, weight, passing_model_ref             | Belongs to Module (zero-or-many per module); has Questions, Attempts                                                                                                                |
| Question           | question_id, assessment_id, question_type, text, difficulty, topic, marks, partial_marks_allowed, explanation, version, status (active/inactive), author_id, review_status                                           | Belongs to Assessment; has many QuestionOptions                                                                                                                                     |
| QuestionOption     | option_id, question_id, option_text, is_correct, sequence                                                                                                                                                            | Belongs to Question - kept as separate records to support multiple-correct-answer types and partial marking                                                                         |
| AssessmentAttempt  | attempt_id, assessment_id, enrolment_id, score, attempt_number, started_at, submitted_at                                                                                                                             | Belongs to Assessment and Enrolment; has many AssessmentResponses                                                                                                                   |
| AssessmentResponse | response_id, attempt_id, question_id, question_version_shown, selected_option_id(s), text_response, numeric_response, file_ref, structured_response_json, is_correct, marks_awarded, answered_at, flagged_for_review | Belongs to AssessmentAttempt and Question - one record per question answered; the generic answer fields (REQ-EVAL-7) support future non-MCQ question types without a schema rewrite |

## 17.5 Delivery, Certification, Support & Audit

| **Entity**           | **Key Fields**                                                                                                          | **Relationships**                                                                                                                                                                                        |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| LiveSession          | session_id, batch_id, datetime, faculty_id, meeting_link, recording_ref                                                 | Belongs to Batch; has AttendanceRecords                                                                                                                                                                  |
| AttendanceRecord     | record_id, session_id, enrolment_id, status, source (manual/import/sync)                                                | Belongs to LiveSession and Enrolment                                                                                                                                                                     |
| Certificate          | certificate_id, enrolment_id, type, grade, cme_credits_awarded, verification_hash, status (active/revoked), issued_at   | Belongs to Enrolment                                                                                                                                                                                     |
| SupportTicket        | ticket_id, user_id, enrolment_id, category, status, sla_due_at, escalation_reason                                       | Belongs to User; optionally to Enrolment; has a response thread with internal-vs-learner-visible flags (REQ-SUP-3)                                                                                       |
| AuditLog             | audit_id, actor_user_id, action, affected_entity_type, affected_entity_id, previous_value, new_value, reason, timestamp | General, append-only audit trail for grades, refunds, course changes, permissions, attendance overrides, and certificate actions (Section 21) - distinct from the document-specific VerificationAuditLog |
| Notification         | notification_id, user_id, template_id, channel, status, sent_at                                                         | References NotificationTemplate; persists every notification actually sent (REQ-NOTIF-2)                                                                                                                 |
| NotificationTemplate | template_id, event_type, subject, body, editable_by_admin_flag                                                          | Referenced by Notification                                                                                                                                                                               |

# 18\. Key State Machines

These formalize PRD §11.2-11.4 into explicit, buildable transition rules, updated for the Application-owns-Payment model in Section 8 - the PRD itself flags (§31) that end-to-end workflow diagrams are the next deliverable after PRD sign-off; this section is that deliverable for the two core objects.

## 18.1 Application Status Transitions

**Authoritative machine:** `ApplicationStateMachine` / `ApplicationStatus` (11 statuses). Draft construction is **not** a transition (REQ-APP-2). Every persisted status change goes through the state machine; repositories expose no general `updateStatus()`.

| **From** | **Allowed To** | **Trigger / notes** | **Persistence** |
| --- | --- | --- | --- |
| Draft | Submitted, Withdrawn | Learner submits or abandons | `draft` → `submitted` \| `withdrawn` |
| Submitted | Documents Incomplete, Under Review, Payment Pending | System document checks; Mode C/no-documents may skip to Payment Pending | `submitted` → `documents_incomplete` \| `under_review` \| `payment_pending` |
| Documents Incomplete | Under Review | Learner completes uploads | `documents_incomplete` → `under_review` |
| Under Review | Resubmission Requested, Payment Pending, Rejected | Reviewer decision (Mode A approval → Payment Pending; **no Payment row at approval**) | `under_review` → `resubmission_requested` \| `payment_pending` \| `rejected` |
| Resubmission Requested | Under Review, Expired | Learner correction resubmit complete, or window lapses | `resubmission_requested` → `under_review` \| `expired` |
| Payment Pending | Awaiting Verification, Admitted | Payment success (Mode B → Awaiting Verification; Mode A/C → Admitted). **Admitted is WP-06.** | `payment_pending` → `awaiting_verification` \| `admitted` |
| Awaiting Verification | Admitted, Rejected | Reviewer decision, post-payment (Mode B) | `awaiting_verification` → `admitted` \| `rejected` |
| Admitted | — (Enrolment created) | Terminal; Enrolment creation (Section 8.1) — **WP-06** | `admitted` |
| Rejected | Withdrawn | Learner withdraw after reject; refund rules if Payment exists | `rejected` → `withdrawn` |
| Withdrawn | — | Terminal | `withdrawn` |
| Expired | — | Terminal | `expired` |

**Implemented Mode A edges already exercised in WP-02–WP-05:** Draft→Submitted; Submitted→Under Review; Under Review→Resubmission Requested; Resubmission Requested→Under Review; Under Review→Rejected; Under Review→Payment Pending. Payment Pending is the WP-05 starting state. Application→Admitted belongs to WP-06.

**Open discrepancy OD-APP-CANCELLED:** v6.0 narrative listed Payment Pending → Cancelled, but the approved 11-state Application enum does **not** contain Cancelled, and no Cancelled transition is implemented. Resolution is deferred to a future product decision / SRS revision. This does **not** block Mode A WP-06.

## 18.2 Enrolment Lifecycle Status Transitions

| **From**                   | **Allowed To**                       | **Trigger**                                                             |
| -------------------------- | ------------------------------------ | ----------------------------------------------------------------------- |
| Scheduled                  | Active, Cancelled                    | Batch start date reached; or batch cancelled before start (REQ-BATCH-3) |
| Active                     | Suspended, Withdrawn, Access Expired | Administrative action, learner withdrawal, or validity period lapses    |
| Suspended                  | Active, Withdrawn                    | Issue resolved, or learner exits                                        |
| Withdrawn / Access Expired | Refunded                             | Only if a refund is separately approved under REQ-REFUND-1              |

_Design Note: Enrolment now exists only from Admitted onward - there is no 'Awaiting Payment' or 'Awaiting Verification' Enrolment state, because those phases of the journey now belong entirely to Application (18.1) and its linked Payment. Scheduled applies only to cohort courses admitted ahead of the batch start date; self-paced admission goes directly to Active. Academic Status (Not Started / In Progress / Academically Completed / Passed / Not Passed) advances only while lifecycle_status = Active, and is tracked independently, so a Suspended or Access-Expired learner's academic record stays intact rather than being overwritten._

## 18.3 DocumentSubmission Status Transitions

**Authority:** Decision Log `SM-ADDENDUM-1` / `SM-DOC-1`; machine: `DocumentSubmissionStateMachine` / `DocumentSubmissionStatus` + `DocumentScanStatus`. Business status and malware `scan_status` are separate dimensions (Section 7.2).

| **From (business)** | **Allowed To** | **Notes** |
| --- | --- | --- |
| _(conceptual Not Uploaded)_ | Uploaded (`uploaded`) | First current row created after upload acceptance; `scan_status = pending` |
| Uploaded (`uploaded`) | Under Review (`under_review`), Failed Security Scan (`failed_security_scan`) | Under Review only when `scan_status = clean` and queue-entry conditions met; Failed Security Scan when `scan_status = failed` |
| Under Review (`under_review`) | Approved (`approved`), Rejected (`rejected`), Resubmission Requested (`resubmission_requested`) | Reviewer actions; reject/resubmission require reason code |
| Approved (`approved`) | Superseded (`superseded`), Expired (`expired`) | Newer submission or validity expiry |
| Rejected (`rejected`) | Superseded (`superseded`) | Learner re-upload creates new row |
| Resubmission Requested (`resubmission_requested`) | Expired (`expired`), Superseded (`superseded`) | Window lapse, or new row on replace |
| Failed Security Scan (`failed_security_scan`) | Superseded (`superseded`) | Retry creates new row |
| Superseded (`superseded`) | — | Terminal for that row |
| Expired (`expired`) | — | Terminal for that row |

**Queue-entry condition:** `status = under_review` AND `scan_status = clean` AND `current_marker = 1` AND acting reviewer has permission + object scope. There is no same-row transition Resubmission Requested → Uploaded or Failed Security Scan → Uploaded — retries always create a new row.

## 18.4 Payment Status Transitions

**Authoritative machine:** `PaymentStateMachine` / `PaymentStatus` (exactly 10 statuses). No `authorized`, `captured`, or `processing` domain statuses.

| **From** | **Allowed To** | **Persistence** |
| --- | --- | --- |
| Created | Pending, Failed, Cancelled | `created` → `pending` \| `failed` \| `cancelled` |
| Pending | Successful, Failed, Cancelled, Expired, Reconciliation Pending | `pending` → `successful` \| `failed` \| `cancelled` \| `expired` \| `reconciliation_pending` |
| Successful | Refunded, Partially Refunded, Disputed, Reconciliation Pending | `successful` → `refunded` \| `partially_refunded` \| `disputed` \| `reconciliation_pending` |
| Partially Refunded | Refunded, Disputed | `partially_refunded` → `refunded` \| `disputed` |
| Reconciliation Pending | Successful, Failed, Cancelled, Refunded | `reconciliation_pending` → `successful` \| `failed` \| `cancelled` \| `refunded` |
| Disputed | Successful, Refunded | `disputed` → `successful` \| `refunded` |
| Failed / Cancelled / Expired / Refunded | — | Terminal |

**WP-05 HTTP path** uses Created → Pending (and failure edges as needed). Authoritative Pending → Successful and Application admission effects are **WP-06**.

# 19\. Representative API Endpoints

Illustrative only; a full API contract is a separate deliverable per PRD §31.

| **Method & Route**                     | **Purpose**                              | **Auth**                     |
| -------------------------------------- | ---------------------------------------- | ---------------------------- |
| GET /courses                           | Public catalogue listing                 | None                         |
| POST /applications                     | Start an application for a course/batch  | Applicant                    |
| POST /applications/{id}/documents      | Request signed upload URL                | Applicant (owner)            |
| POST /admin/documents/{id}/review      | Approve/reject a document                | Reviewer                     |
| POST /applications/{id}/checkout       | Initiate payment (mode-dependent timing) | Applicant (owner)            |
| POST /webhooks/razorpay                | Payment gateway webhook                  | Gateway-signed               |
| POST /webhooks/hdfc                    | Payment gateway webhook                  | Gateway-signed               |
| GET /enrolments/{id}/progress          | Module/assessment progress               | Learner (owner)              |
| POST /assessments/{id}/attempts        | Submit an assessment attempt             | Learner (owner)              |
| POST /admin/enrolments/{id}/attendance | Record/import attendance                 | Faculty/Admin                |
| GET /certificates/{id}                 | Fetch certificate PDF                    | Owner or public verify route |
| GET /verify/{hash}                     | Public certificate verification          | None                         |
| POST /corporate/{id}/vouchers          | Generate sponsored-seat vouchers         | Finance/Course Admin         |

# 20\. Non-Functional Requirements

_Implements PRD §24-26_

## 20.1 Security & Privacy

- **NFR-SEC-1:** TLS 1.2+ in transit; encryption at rest for documents and PII; secure password hashing; mandatory MFA for Admin/Reviewer/Finance roles; session expiry; login throttling.
- **NFR-SEC-2:** Data residency within India, consistent with the DPDP Act, 2023; specific retention periods to be confirmed by legal/compliance before build (PRD §28, dependency).
- **NFR-SEC-3:** Video delivery uses encrypted adaptive streaming with time-limited access tokens. Overlay-based screenshot/print-blocking is not technically enforceable and must not be represented as a real control; optional visible watermarking (learner name/email) is the realistic deterrent (PRD §13.6, §29.5).
- **NFR-SEC-4:** All administrative actions on documents, grades, refunds, and course configuration are logged with actor, timestamp, and before/after values (Section 21).

## 20.2 Performance, Availability & Scalability

- **NFR-PERF-1:** Concrete thresholds (page load, video start time, concurrent-user targets) are defined once expected learner volumes are confirmed - the PRD explicitly defers this (§26.2, §26.6), and this SRS should not invent numbers Product hasn't validated.
- **NFR-PERF-2:** Uptime target, planned-maintenance policy, and support-escalation process to be defined alongside hosting selection.

## 20.3 Accessibility

- **NFR-ACC-1:** Clear typography and contrast, keyboard-accessible navigation, captions where available, readable assessment layouts, screen-reader-compatible core workflows where practical.

## 20.4 Backup & Recovery

- **NFR-BAK-1:** Regular backups with periodic recovery testing; RPO/RTO values to be set with hosting/infra selection.

## 20.5 Capacity & NFR Addendum - required before Approved Build Baseline

This SRS deliberately does not invent the figures below (Section 20.2 explains why); the table exists so they aren't forgotten, and so architecture/estimation has a concrete list to close out before the document moves from Conditionally Approved to Approved Build Baseline.

| **Item**                                 | **Status**           |
| ---------------------------------------- | -------------------- |
| Expected registered learners, Year 1     | Pending confirmation |
| Peak concurrent users                    | Pending confirmation |
| Peak concurrent video streams            | Pending confirmation |
| Peak simultaneous assessment attempts    | Pending confirmation |
| Maximum expected course and batch counts | Pending confirmation |
| Document-storage growth (per year)       | Pending confirmation |
| Video-storage growth (per year)          | Pending confirmation |
| Backup frequency                         | Pending confirmation |
| Recovery Point Objective (RPO)           | Pending confirmation |
| Recovery Time Objective (RTO)            | Pending confirmation |
| Uptime target                            | Pending confirmation |
| Browser/device support matrix            | Pending confirmation |
| Page-performance targets                 | Pending confirmation |

# 21\. Audit Requirements

_Implements PRD §25_

Audit records are required for: document approval/rejection, application approval/rejection, payment and refund actions, offline-payment entry, course publication and configuration changes, learner access changes, attendance overrides, additional assessment attempts, grade changes, certificate issuance/reissue/revocation, and permission changes.

**Separation of logs (consolidated):**

- **`audit_log`** — technical / general administrative audit (grades, refunds, course/version changes, permissions, attendance overrides, certificate actions, payment technical events, and other sensitive state changes).
- **`verification_audit_log`** — reviewer / business verification history for DocumentSubmission and related Application correction decisions (append-only; never edited or deleted).
- **`payment_status_history`** — append-only Payment attempt history (from_status, to_status, actor, reason, timestamp).

Document-specific review actions are captured in VerificationAuditLog (Section 17.3); everything else in the general list above is captured in AuditLog (Section 17.5), not folded into the document-review log.

Each technical / verification / payment-history record captures: acting user (where applicable), action, affected record, previous value, new value, reason, and timestamp — and is append-only, including against Super Admin.

**Payload hygiene:** Audit and history payloads must exclude PII dumps, secrets, document content, signed URLs, gateway credentials, raw signatures, and unrestricted gateway payloads.

**Atomicity:** Sensitive state changes and matching audit / history / outbox writes occur in the same database transaction.

# 22\. Explicitly Out of Scope for This Release

_Per PRD §5.2 (Future) and §5.3 (Explicit Exclusions) - listed here for traceability only, not re-decided_

- Native Android/iOS apps; automated medical-council verification; Zoom/Meet/Teams attendance sync
- WhatsApp/SMS notifications; instalment/EMI plans; descriptive/assignment grading; online proctoring
- Full SCORM/xAPI authoring; multilingual courses; institutional self-service portals; AI-assisted question generation; adaptive learning paths; CME-integration automation
- International multi-currency payments; third-party instructor marketplace; white-labelling; complex recommendation engines

# 23\. Open Product Decisions Carried Forward

Design Decision: Corporate/sponsored enrolment (Section 9.6), coupons/scholarships (Section 9.2), and CME-credit display (Section 12) are confirmed Phase 1 scope - they are already specified as functional requirements elsewhere in this SRS (REQ-CORP-1-3, REQ-PRICE-1-2, REQ-CERT-3) and are therefore removed from the open-decision list below. A feature cannot be simultaneously approved scope and open for a scope decision; where the PRD listed a question about whether something ships in Phase 1, this SRS treats the PRD's Section 5.1 scope list as the answer, and only lists genuinely undecided items below.

## 23.1 Open decisions after WP-02–WP-05 consolidation (genuine unresolved only)

| ID | Item | Status | Notes |
| --- | --- | --- | --- |
| OD-APP-CANCELLED | Application Cancelled-state discrepancy | Open | v6.0 §18.1 narrative mentioned Payment Pending → Cancelled; approved 11-state enum and `ApplicationStateMachine` omit Cancelled; no transition implemented. Deferred; does **not** block Mode A WP-06. |
| WP01-B | Production email provider | Pending | Decision Log WP01-B (Amazon SES recommended). |
| WP01-C | Production SMS/OTP provider and DLT pack | Preferred / Pending | Blocks WP-01B production completion; sandbox/fake allowed for development. |
| WP01-A TL | Technical Lead security/failure-behaviour sign-off for shared session + rate-limit store | Pending | Product Owner approved; TL pending. |
| WP01-D TL | Technical Lead TOTP implementation review | Pending | Product Owner approved `spomky-labs/otphp` subject to conditions; TL review pending. |
| WP01-F TL | Final hosting / DR approval | Pending | `ap-south-1` working assumption approved; final infra/backup/logging/residency/DR approval pending. |
| WP-06 | Production Razorpay webhook / reconciliation / Admitted / Enrolment | Not yet implemented | Owned by WP-06 (REQ-PAY-11). Not claimed as implemented in this consolidation. |

**Resolved — do not re-list as pending:** WP01-E reviewer object scope (Product Owner approved; Technical Lead schema review completed through WP-04, 2026-07-23).

## 23.2 PRD open items still carried forward

The remaining items are unchanged from PRD §30 and remain business decisions, not engineering defaults - restated here so the SRS doesn't quietly resolve them by implementation choice:

- Per-course admission mode assignment (A/B/C) and refund policy for eligibility rejection
- Whether Phase 1 includes assignments/descriptive assessment; passing model default (A/B/C) per programme
- Video-completion watch percentage; attempt-counting rule (highest/latest/average); attempt count and cooldown defaults
- Grade bands per programme; minimum live-session attendance requirement for long courses
- Post-completion access retention period; credential-reuse policy; document retention period
- Whether learners outside India are expected in Phase 1; which reports are essential for first launch

# 24\. Requirements Traceability (SRS-V61-1)

| Requirement / decision | Decision Log / authority | Work package | State machine / schema | Implementation status |
| --- | --- | --- | --- | --- |
| APP-DRAFT-1 | Decision Log `APP-DRAFT-1` | WP-02 | Application Draft factory; no SM transition | Implemented |
| SM-ADDENDUM-1 | Decision Log `SM-ADDENDUM-1`; `STATE_MACHINE_ADDENDUM.md` | WP-03 (incorporated into this SRS §7.2 / §18.3) | DocumentSubmission SM + scan_status | Implemented (docs consolidated in v6.1) |
| SM-DOC-1 | Decision Log `SM-DOC-1` | WP-03 | `current_marker`, resubmission txn, stuck-scan policy | Implemented |
| WP01-E | Decision Log `WP01-E` | WP-01B schema + WP-04 review | `reviewer_scope_assignments`, `application_review_assignments` | Implemented; TL review completed 2026-07-23 |
| PAY-ATTEMPT-1 | Decision Log `PAY-ATTEMPT-1` | WP-05 | Payment attempts; in-flight gate; snapshot | Implemented (checkout); accepted-success marker WP-06 |
| SRS-V61-1 | Decision Log `SRS-V61-1` | Docs checkpoint before WP-06 | This document + consolidation note | Documentation consolidation |
| WP-02 | Roadmap WP-02 | WP-02 | Course/Batch/Draft Application | Merged |
| WP-03 | Roadmap WP-03 | WP-03 | Application SM; DocumentSubmission SM; submit preconditions | Merged |
| WP-04 | Roadmap WP-04 | WP-04 | Reviewer verification; VerificationAuditLog; → Payment Pending | Merged |
| WP-05 | Roadmap WP-05 | WP-05 | Payment SM; checkout Created→Pending; Finance view-only | Merged |
| WP-06 handoff | Roadmap WP-06; REQ-PAY-11 | WP-06 (not started) | Webhook, Successful, Admitted, Enrolment, capacity, `successful_marker` | **Not implemented** — ownership documented only |

# 25\. WP-06 Ownership Handoff

Before WP-06 begins, this consolidation (`SRS-V61-1`) must be complete. WP-06 owns exclusively:

1. Razorpay webhook ingress
2. Raw-body signature verification
3. Durable webhook event receipt and idempotency
4. Reconciliation worker / cron
5. Authoritative Payment → Successful
6. One accepted-success enforcement using `successful_marker`
7. Duplicate capture → Reconciliation Pending
8. Application Payment Pending → Admitted
9. Capacity enforcement
10. Enrolment creation
11. Ensuring no second Enrolment is created

WP-05 owns **none** of those mutations. Browser callbacks remain informational only.