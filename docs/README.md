# Academy LMS Documentation

This directory contains the authoritative product, design, technical, UAT, and operations documentation for the Academy LMS.

## Document Authority Hierarchy
If documents conflict, stop and identify the conflict. Do not resolve by assumption.
1. **PRD** (`/docs/product/`): Governs scope and business intent.
2. **SRS** (`/docs/product/`): Governs functional behaviour, business rules, and state machines. **Current consolidated functional baseline: [SRS v6.1](./product/Academy_LMS_SRS_v6.1.md)** (2026-07-23). [SRS v6.0](./product/Academy_LMS_SRS_v6.md) remains on record and is not overwritten. Consolidation detail: [`SRS_V6_1_CONSOLIDATION_NOTE.md`](./product/SRS_V6_1_CONSOLIDATION_NOTE.md) (Decision Log `SRS-V61-1`).
3. **Technical Architecture** (`/docs/technical/`): Governs implementation, stack, and coding standards.
4. **High-Fidelity Designs** (`/docs/design/high-fidelity/`): Governs approved visual and interaction treatment.
5. **Screen Inventory** (`/docs/design/`): Governs screen coverage and traceability.
6. **Low-Fidelity Wireframes** (`/docs/design/`): Supplementary context.

## Directory Map
- `/docs/product/` — PRD, SRS, Decision Log, roadmap, readiness register, WP/RC notes.
- `/docs/design/` — Screen inventory, low-fi wireframes, and design system.
- `/docs/design/high-fidelity/` — Interactive HTML prototypes for the 9 core flows.
- `/docs/technical/` — Technical Architecture and Coding Standards.
- `/docs/uat/` — UAT overview, accounts, journey scripts, defect/sign-off templates (RC-01).
- `/docs/demo/` — Mode A Product Owner demo: local run, script, readiness, feedback.
- `/docs/operations/` — Workers, alerts, logging, backup/restore, deployment, supervision examples (RC-01).
- `/docs/releases/` — Release checklists (RC-01).
- `/docs/engineering/` — Engineering process notes (e.g. flaky test register).

---

## Product

| Document | Description |
|---|---|
| [Academy_LMS_PRD_v1.md](./product/Academy_LMS_PRD_v1.md) | Product requirements (scope and intent) |
| [Academy_LMS_SRS_v6.1.md](./product/Academy_LMS_SRS_v6.1.md) | Current consolidated SRS |
| [Academy_LMS_SRS_v6.md](./product/Academy_LMS_SRS_v6.md) | Prior SRS v6.0 (on record) |
| [SRS_V6_1_CONSOLIDATION_NOTE.md](./product/SRS_V6_1_CONSOLIDATION_NOTE.md) | Section-by-section consolidation (`SRS-V61-1`) |
| [DECISION_LOG.md](./product/DECISION_LOG.md) | Approved decisions |
| [VERTICAL_SLICE_ROADMAP.md](./product/VERTICAL_SLICE_ROADMAP.md) | Mode A vertical-slice roadmap |
| [STATE_MACHINE_ADDENDUM.md](./product/STATE_MACHINE_ADDENDUM.md) | DocumentSubmission / Draft clarifications |
| [PRODUCTION_READINESS_REGISTER.md](./product/PRODUCTION_READINESS_REGISTER.md) | Open production gaps (classified UAT/pilot/production/future) |
| [WP01_DECISION_NOTE.md](./product/WP01_DECISION_NOTE.md) | WP-01 options (session, email/SMS, TOTP, scope, hosting) |

## Architecture

| Document | Description |
|---|---|
| [Academy_LMS_PHP_MySQL_Technical_Architecture_and_Coding_Standards_v1_1.md](./technical/Academy_LMS_PHP_MySQL_Technical_Architecture_and_Coding_Standards_v1_1.md) | Technical architecture and coding standards v1.1 |
| [Academy_LMS_Screen_Inventory-3.md](./design/Academy_LMS_Screen_Inventory-3.md) | Screen inventory |
| Design system / wireframes / high-fidelity | Under [`./design/`](./design/) and [`./design/high-fidelity/`](./design/high-fidelity/) |

## Work packages

| Document | Description |
|---|---|
| [WP01_DECISION_NOTE.md](./product/WP01_DECISION_NOTE.md) | WP-01 foundation decisions |
| [WP01B2D_IMPLEMENTATION_NOTE.md](./product/WP01B2D_IMPLEMENTATION_NOTE.md) | WP-01B-2d implementation note |
| [WP02_IMPLEMENTATION_NOTE.md](./product/WP02_IMPLEMENTATION_NOTE.md) | Catalogue / course version slice |
| [WP03_IMPLEMENTATION_NOTE.md](./product/WP03_IMPLEMENTATION_NOTE.md) | Applications / documents |
| [WP04_IMPLEMENTATION_NOTE.md](./product/WP04_IMPLEMENTATION_NOTE.md) | Reviewer journey |
| [WP05_IMPLEMENTATION_NOTE.md](./product/WP05_IMPLEMENTATION_NOTE.md) | Payments |
| [WP06_IMPLEMENTATION_NOTE.md](./product/WP06_IMPLEMENTATION_NOTE.md) | Admission / enrolment |
| [WP07_IMPLEMENTATION_NOTE.md](./product/WP07_IMPLEMENTATION_NOTE.md) | Dashboard + transactional notifications |
| [RC01_IMPLEMENTATION_NOTE.md](./product/RC01_IMPLEMENTATION_NOTE.md) | RC-01 UAT release and deployment hardening |

## UAT

| Document | Description |
|---|---|
| [UAT_OVERVIEW.md](./uat/UAT_OVERVIEW.md) | Severity, release rules, how to run UAT |
| [UAT_ACCOUNTS.md](./uat/UAT_ACCOUNTS.md) | Personas, password/MFA bootstrap, reset |
| [UAT_LEARNER_JOURNEY.md](./uat/UAT_LEARNER_JOURNEY.md) | Learner cases (RC-01 J) |
| [UAT_REVIEWER_JOURNEY.md](./uat/UAT_REVIEWER_JOURNEY.md) | Reviewer cases (RC-01 K) |
| [UAT_FINANCE_JOURNEY.md](./uat/UAT_FINANCE_JOURNEY.md) | Finance cases (RC-01 L) |
| [UAT_NOTIFICATION_OPERATIONS.md](./uat/UAT_NOTIFICATION_OPERATIONS.md) | Notification ops (RC-01 M) |
| [UAT_SECURITY_NEGATIVE_TESTS.md](./uat/UAT_SECURITY_NEGATIVE_TESTS.md) | Security negatives (RC-01 N) |
| [UAT_DEFECT_LOG_TEMPLATE.md](./uat/UAT_DEFECT_LOG_TEMPLATE.md) | Defect log template |
| [UAT_SIGNOFF_TEMPLATE.md](./uat/UAT_SIGNOFF_TEMPLATE.md) | Sign-off template |

## Operations

| Document | Description |
|---|---|
| [WORKERS_AND_SCHEDULES.md](./operations/WORKERS_AND_SCHEDULES.md) | Job cadence, locks, leases, idempotency |
| [PROCESS_SUPERVISION_EXAMPLES.md](./operations/PROCESS_SUPERVISION_EXAMPLES.md) | systemd / Supervisor / cron examples (non-binding) |
| [ALERT_CATALOGUE.md](./operations/ALERT_CATALOGUE.md) | Suggested alerts (integration not assumed) |
| [LOGGING.md](./operations/LOGGING.md) | Request ID, redaction, retention placeholders |
| [BACKUP_RESTORE_RUNBOOK.md](./operations/BACKUP_RESTORE_RUNBOOK.md) | UAT mysqldump backup/restore rehearsal |
| [UAT_DEPLOYMENT_RUNBOOK.md](./operations/UAT_DEPLOYMENT_RUNBOOK.md) | UAT deploy / verify / rollback |

## Releases

| Document | Description |
|---|---|
| [RC01_RELEASE_CHECKLIST.md](./releases/RC01_RELEASE_CHECKLIST.md) | RC-01 release gate checklist |

## Engineering

| Document | Description |
|---|---|
| [FLAKY_TEST_REGISTER.md](./engineering/FLAKY_TEST_REGISTER.md) | Intermittent CI test register and policy |

---

## Companion planning documents (vertical slice)
- [`product/Academy_LMS_SRS_v6.1.md`](./product/Academy_LMS_SRS_v6.1.md) — Current consolidated SRS (WP-02–WP-05 decisions; WP-06 handoff).
- [`product/Academy_LMS_SRS_v6.md`](./product/Academy_LMS_SRS_v6.md) — Prior SRS v6.0 (unchanged; retained on record).
- [`product/SRS_V6_1_CONSOLIDATION_NOTE.md`](./product/SRS_V6_1_CONSOLIDATION_NOTE.md) — Section-by-section consolidation report (`SRS-V61-1`).
- [`product/VERTICAL_SLICE_ROADMAP.md`](./product/VERTICAL_SLICE_ROADMAP.md) — Mode A admission journey; seven logical WPs; WP-01 split into WP-01A/WP-01B PRs.
- [`product/STATE_MACHINE_ADDENDUM.md`](./product/STATE_MACHINE_ADDENDUM.md) — DocumentSubmission transitions and Draft clarification; content incorporated into SRS v6.1 §7.2 / §18.3 (Decision Log `SM-ADDENDUM-1`).
- [`product/WP01_DECISION_NOTE.md`](./product/WP01_DECISION_NOTE.md) — Recommended options for session store, email/SMS, TOTP, reviewer scope, hosting assumptions.
- [`product/DECISION_LOG.md`](./product/DECISION_LOG.md) — Approved decisions including `PAY-ATTEMPT-1`, `SRS-V61-1`, and slice scope.

## Key Invariants (Summary)
- Enrolment is created ONLY when Application.status = Admitted.
- Payment belongs to Application (application_id NOT NULL), not Enrolment.
- Published CourseVersions are immutable; changes require Version N+1.
- Finance users cannot access DocumentSubmission data.
- All state transitions go through state machine classes.
