# Decision Log

| ID | Date | Decision | Status | Notes |
| --- | --- | --- | --- | --- |
| D1 | 2026-07-19 | Automated tests use PHPUnit. Constraint is an explicit reviewed semantic range compatible with PHP 8.4 at installation time; the Decision Log does not hard-code a PHPUnit major version. | Approved | Phase 0 |
| D2 | 2026-07-19 | Database migrations use Phinx, placed in Composer `require-dev`. | Approved | Phase 0 |
| D3 | 2026-07-19 | Coding standards: PHP-CS-Fixer; static analysis: PHPStan. | Approved | Phase 0 |
| D4 | 2026-07-19 | HTTP stack: Laminas Diactoros, Laminas HTTP Handler Runner, League Route, and PSR-15 contracts. No proprietary framework around them. Phase 0 uses `league/route` `^7.0` (stable) because `^5.1` conflicts with Phinx via `psr/simple-cache`. | Approved | Phase 0 |
| D5 | 2026-07-19 | PHP-DI is approved only for the composition root. Domain, Application, controller, service, and repository classes must not retrieve dependencies from the container. | Approved | Phase 0 |
| D6 | 2026-07-19 | `vlucas/phpdotenv` is approved for local development and CI only. Production must use environment variables or a secrets service directly. | Approved | Phase 0 |
| D7 | 2026-07-19 | Presentation uses plain PHP templates with central escaping helpers. | Approved | Phase 0 |
| D8 | 2026-07-19 | Frontend dependencies use npm with a committed `package-lock.json` and a controlled copy/build script. Phase 0 installs only Bootstrap and jQuery for the base layout. DataTables, Select2, and SweetAlert2 are loaded only by screens that need them (not in Phase 0). | Approved | Phase 0 |
| D9 | 2026-07-19 | Shared session and rate-limit store: configuration and contracts only in Phase 0. Implementation deferred until deployment architecture is approved. | Approved | Phase 0; closure options in `WP01_DECISION_NOTE.md` Decision A |
| D10 | 2026-07-19 | PHP namespace root is `Academy\`. | Approved | Phase 0 |
| D11 | 2026-07-19 | Phase 0 configures and validates Phinx only. Production `audit_log` schema is a separate reviewed work package. | Approved | Phase 0; delivered in logical WP-01 / PR WP-01A |
| DEP-1 | 2026-07-19 | Composer and npm dependencies must use explicit reviewed semantic-version constraints (no wildcards, `dev-main`, unbounded, or prerelease ranges). Commit `composer.lock` and `package-lock.json`. Run `composer audit` after install and resolve reported vulnerabilities before proceeding. | Approved | Phase 0 |
| ARCH-STATUS-1 | 2026-07-19 | Technical Architecture v1.1 status: “Approved for Phase 0 Foundation Build and Build Preparation. The broader functional build remains subject to the open decisions and conditional approvals identified in the SRS and Decision Log.” | Approved | Reconciles AGENTS.md and Architecture document |
| DOC-PATH-1 | 2026-07-19 | High-fidelity design artefacts live under `/docs/design/high-fidelity/` (not `hi-fidelity`). | Approved | Path naming |

## Vertical slice — Mode A admission journey

| ID | Date | Decision | Status | Notes |
| --- | --- | --- | --- | --- |
| VS-SCOPE-1 | 2026-07-19 | First production vertical slice constraints: Admission Mode A only; no waitlist; Razorpay only; no Course Admin builder UI (published seed only); Learner Dashboard only (Course Player deferred); Finance role included only for segregation-of-duties tests; published/locked CourseVersions remain immutable. | Approved | See `VERTICAL_SLICE_ROADMAP.md` |
| VS-WP-1 | 2026-07-19 | Logical build plan is seven work packages (WP-01…WP-07). WP-01 is one logical stage delivered as two separately reviewed PRs: WP-01A (`slice/wp01a-security-audit-foundation`) and WP-01B (`slice/wp01b-identity-rbac-mfa`). | Approved | Roadmap file is plan of record |
| VS-WP-01-SOD | 2026-07-19 | WP-01 must not create stub document routes or fake DocumentSubmission repositories for Finance tests. WP-01 tests permission matrix / policies only (Finance lacks document permissions). WP-03 adds real repository, signed-URL and HTTP Finance denial tests. | Approved | Amendment to roadmap |
| APP-DRAFT-1 | 2026-07-19 | Initial construction of an Application in Draft is an entity factory operation, not a state transition. After persistence, every status change goes through `ApplicationStateMachine`. Repositories never expose general `updateStatus()`. No temporary raw status-writing in WP-02. | Approved | Clarifies WP-02 |
| PAY-ATTEMPT-1 | 2026-07-19 | An Application may have multiple Payment records representing separate payment attempts. Only one net successful payable outcome may be accepted automatically. Any additional captured payment must enter reconciliation and must not create a second Enrolment. | Approved | Authoritative vs SRS “Payment-chain” wording until SRS revision |
| SM-ADDENDUM-1 | 2026-07-19 | `docs/product/STATE_MACHINE_ADDENDUM.md` is authoritative for DocumentSubmission transition detail (and related clarifications therein) until incorporated into a future formal SRS revision. Do not edit SRS v6.0 in place for these additions. | Approved | See also `SM-DOC-1` |
| VS-NOTIF-1 | 2026-07-19 | No separate notifications branch. Transactional notifications beyond identity OTP are part of WP-07 unless required earlier for OTP in WP-01B. | Approved | |
| VS-SM-FULL-1 | 2026-07-19 | When a state machine is introduced, implement and test the complete approved transition matrix, not only Mode A happy-path transitions. | Approved | |

## WP-01 prerequisite decisions

See [`WP01_DECISION_NOTE.md`](./WP01_DECISION_NOTE.md) for full text, requirements and review triggers.

| ID | Topic | Decision | Status |
| --- | --- | --- | --- |
| WP01-A | Shared session + rate-limit store | MySQL for both for the initial production architecture, **subject to load testing and review before horizontal scaling**. Rationale vs hybrid Redis rate-limit: avoid a second production datastore for the initial traffic profile; reduce operational/monitoring/failover complexity; retain review triggers for independent later migration of rate limiting to Redis. Separate session and rate-limit tables; indexed expiries; scheduled cleanup; hashed session identifiers; **atomic** counters only (`INSERT … ON DUPLICATE KEY UPDATE hit_count = hit_count + 1` or equivalent — no read-then-update in PHP); unique bucket/window keys; endpoint-specific failure behaviour; auth/OTP rate-limit failure behaviour documented and security reviewed. **Review triggers:** DB contention from session/rate-limit writes; transactional latency impact; session cleanup or lock contention across nodes; failure to meet approved NFR/load-test thresholds. | **Approved — Product Owner (Nrip Nihalani), 2026-07-19.** Technical Lead security/failure-behaviour sign-off: **Pending** |
| WP01-B | Email verification provider | Recommendation Amazon SES (B1) | **Pending** |
| WP01-C | Mobile verification | SMS OTP is **Preferred but Pending**. Production SMS implementation is **not** approved until provider, DLT setup, sender identity, template approvals, SLA, fallback and commercials are approved. Provider-neutral interface + fake/sandbox adapter allowed for development. Does not block WP-01A; **blocks WP-01B production completion**. | **Preferred / Pending** |
| WP01-D | TOTP package | `spomky-labs/otphp`, subject to compatible version pinning, licence and dependency review, `composer audit`, encrypted TOTP secrets, hashed single-use recovery codes, no secret/provisioning URI in logs, audited enrolment/reset/recovery use. | **Approved — Product Owner (Nrip Nihalani), 2026-07-19** (subject to listed conditions). Technical Lead implementation review: **Pending** |
| WP01-E | Reviewer object scope | Assignments may target Course, CourseVersion and/or Batch; active assignments combine as a **union**; **permission and object scope both required**. Course-level assignments must **not** automatically include future CourseVersions unless explicit `include_future_versions` is approved and enabled. Assignments support effective dates, revocation history, creator/revoker identity and audit events. | **Approved — Product Owner (Nrip Nihalani), 2026-07-19.** Technical Lead schema review: **Pending** |
| WP01-F | Hosting / India region | AWS `ap-south-1` as primary production-region **working assumption**, subject to final infrastructure, backup, logging, residency and disaster-recovery approval. Region choice alone does **not** satisfy all compliance requirements. | **Approved (working assumption) — Product Owner (Nrip Nihalani), 2026-07-19.** Technical Lead / hosting final approval: **Pending** |
| SM-DOC-1 | DocumentSubmission modelling | Business `status` and malware `scan_status` are separate. Resubmission creates a **new** DocumentSubmission row; prior row immutable and marked **Superseded**. Current row enforced via nullable `current_marker` (`1` = current, `NULL` = historical) and `UNIQUE (application_id, requirement_id, current_marker)`. Resubmission transaction: `SELECT … FOR UPDATE` → supersede + clear marker → insert new current → audit/outbox → commit; unique constraint is final concurrency defence. Stuck `scan_status = pending` beyond SLA: alert + retry; never queue as clean; exhausted retries need manual ops. Details in `STATE_MACHINE_ADDENDUM.md`. | **Approved** |
| SRS-V61-1 | SRS consolidation checkpoint | Before WP-06 begins, approved functional amendments affecting Admissions, DocumentSubmission, Payments and Reviewer Scope must be consolidated into **SRS v6.1**. | **Recorded.** Owner: Product Owner **Nrip Nihalani**. Technical validation: **Technical Lead**. |

**WP-01A may start** with WP01-A and WP01-F Product Owner approvals (satisfied); Technical Lead pending items do not block planning but must be closed during WP-01A implementation review.  
**WP-01B may start** after WP-01A is merged and WP01-D, WP01-E Product Owner approvals (satisfied); WP01-B email provider still Pending; Technical Lead schema/TOTP reviews Pending at implementation.  
**WP-01B production completion** additionally requires WP01-C production SMS pack (or Product-approved interim — not default).  
**WP-06 may start** only after `SRS-V61-1` consolidation into SRS v6.1.

## Human approval record (Product Owner)

| Decision IDs | Approver | Date | Notes |
| --- | --- | --- | --- |
| WP01-A, WP01-D, WP01-E, WP01-F | **Nrip Nihalani** (Product Owner) | 2026-07-19 | Product approval recorded. Where separate Technical Lead approval remains required, status is **Pending** (not implied complete). |

Recorded at Phase 0 install time on `foundation/repository-scaffold`. Constraints remain the reviewed ranges in `composer.json` / `package.json`; lockfiles are authoritative.

### Composer (direct)

| Package | Installed version | Constraint |
| --- | --- | --- |
| laminas/laminas-diactoros | 3.8.0 | ^3.5 |
| laminas/laminas-httphandlerrunner | 2.13.0 | ^2.11 |
| league/route | 7.0.0 | ^7.0 |
| monolog/monolog | 3.10.0 | ^3.8 |
| php-di/php-di | 7.1.1 | ^7.0 |
| psr/container | 2.0.2 | ^2.0 |
| psr/http-server-handler | 1.0.2 | ^1.0 |
| psr/http-server-middleware | 1.0.2 | ^1.0 |
| psr/log | 3.0.2 | ^3.0 |
| vlucas/phpdotenv | 5.6.4 | ^5.6 |
| friendsofphp/php-cs-fixer | 3.95.15 | ^3.75 (require-dev) |
| phpstan/phpstan | 2.2.5 | ^2.1 (require-dev) |
| phpunit/phpunit | 11.5.56 | ^11.5 (require-dev) |
| robmorgan/phinx | 0.16.12 | ^0.16 (require-dev) |

### npm

| Package | Installed version | package.json pin |
| --- | --- | --- |
| bootstrap | 5.3.3 | 5.3.3 |
| jquery | 3.7.1 | 3.7.1 |
