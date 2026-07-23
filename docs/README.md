# Academy LMS Documentation

This directory contains the authoritative product, design, and technical documentation for the Academy LMS.

## Document Authority Hierarchy
If documents conflict, stop and identify the conflict. Do not resolve by assumption.
1. **PRD** (`/docs/product/`): Governs scope and business intent.
2. **SRS** (`/docs/product/`): Governs functional behaviour, business rules, and state machines. **Current consolidated functional baseline: [SRS v6.1](./product/Academy_LMS_SRS_v6.1.md)** (2026-07-23). [SRS v6.0](./product/Academy_LMS_SRS_v6.md) remains on record and is not overwritten. Consolidation detail: [`SRS_V6_1_CONSOLIDATION_NOTE.md`](./product/SRS_V6_1_CONSOLIDATION_NOTE.md) (Decision Log `SRS-V61-1`).
3. **Technical Architecture** (`/docs/technical/`): Governs implementation, stack, and coding standards.
4. **High-Fidelity Designs** (`/docs/design/high-fidelity/`): Governs approved visual and interaction treatment.
5. **Screen Inventory** (`/docs/design/`): Governs screen coverage and traceability.
6. **Low-Fidelity Wireframes** (`/docs/design/`): Supplementary context.

## Directory Map
- `/docs/product/` - PRD, SRS v6.0 (historical) and SRS v6.1 (current consolidation), Decision Log, vertical-slice roadmap, state-machine addendum, and WP decision notes.
- `/docs/design/` - Screen inventory, low-fi wireframes, and design system.
- `/docs/design/high-fidelity/` - Interactive HTML prototypes for the 9 core flows.
- `/docs/technical/` - Technical Architecture and Coding Standards.

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