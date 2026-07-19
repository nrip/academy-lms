# Academy LMS Documentation

This directory contains the authoritative product, design, and technical documentation for the Academy LMS.

## Document Authority Hierarchy
If documents conflict, stop and identify the conflict. Do not resolve by assumption.
1. **PRD** (`/docs/product/`): Governs scope and business intent.
2. **SRS** (`/docs/product/`): Governs functional behaviour, business rules, and state machines.
3. **Technical Architecture** (`/docs/technical/`): Governs implementation, stack, and coding standards.
4. **High-Fidelity Designs** (`/docs/design/high-fidelity/`): Governs approved visual and interaction treatment.
5. **Screen Inventory** (`/docs/design/`): Governs screen coverage and traceability.
6. **Low-Fidelity Wireframes** (`/docs/design/`): Supplementary context.

## Directory Map
- `/docs/product/` - PRD, SRS, Decision Log, vertical-slice roadmap, state-machine addendum, and WP decision notes.
- `/docs/design/` - Screen inventory, low-fi wireframes, and design system.
- `/docs/design/high-fidelity/` - Interactive HTML prototypes for the 9 core flows.
- `/docs/technical/` - Technical Architecture and Coding Standards.

## Companion planning documents (vertical slice)
- [`product/VERTICAL_SLICE_ROADMAP.md`](./product/VERTICAL_SLICE_ROADMAP.md) — Mode A admission journey; seven logical WPs; WP-01 split into WP-01A/WP-01B PRs.
- [`product/STATE_MACHINE_ADDENDUM.md`](./product/STATE_MACHINE_ADDENDUM.md) — DocumentSubmission transitions and Draft clarification; authoritative until a future SRS revision (Decision Log `SM-ADDENDUM-1`).
- [`product/WP01_DECISION_NOTE.md`](./product/WP01_DECISION_NOTE.md) — Recommended options for session store, email/SMS, TOTP, reviewer scope, hosting assumptions.
- [`product/DECISION_LOG.md`](./product/DECISION_LOG.md) — Approved decisions including `PAY-ATTEMPT-1` and slice scope.

## Key Invariants (Summary)
- Enrolment is created ONLY when Application.status = Admitted.
- Payment belongs to Application (application_id NOT NULL), not Enrolment.
- Published CourseVersions are immutable; changes require Version N+1.
- Finance users cannot access DocumentSubmission data.
- All state transitions go through state machine classes.