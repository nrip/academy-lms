# SRS v6.1 Consolidation Note

**Decision:** `SRS-V61-1`  
**Date:** 2026-07-23  
**Branch:** `docs/srs-v6-1-consolidation`  
**Nature:** Documentation consolidation only — **not** a scope expansion, redesign, or behaviour change.

## Artefacts

| Artefact | Path |
| --- | --- |
| Prior SRS (unchanged) | `docs/product/Academy_LMS_SRS_v6.md` |
| Consolidated SRS | `docs/product/Academy_LMS_SRS_v6.1.md` |
| This note | `docs/product/SRS_V6_1_CONSOLIDATION_NOTE.md` |

## Authority order used

1. `Academy_LMS_SRS_v6.md` (baseline text)
2. `DECISION_LOG.md`
3. `STATE_MACHINE_ADDENDUM.md`
4. `VERTICAL_SLICE_ROADMAP.md`
5. WP-02 … WP-05 implementation notes
6. `AGENTS.md`
7. Merged implementation / migrations — **verification only** (no invented behaviour)

## Amendment table

| SRS section | Previous v6.0 wording / problem | v6.1 consolidated wording | Authority / Decision ID | Implemented WP | Behaviour changed? |
| --- | --- | --- | --- | --- | --- |
| Title / revision metadata | v6.0; no consolidation history | Version 6.1; Date 2026-07-23; revision-history entry stating consolidation of WP-02–WP-05 decisions | `SRS-V61-1` | Docs | Documentation only |
| §1.1 Purpose | No consolidation authority note | Adds v6.1 authority pointer to Decision Log / Addendum / Roadmap / WP notes | `SRS-V61-1` | Docs | Documentation only |
| §3.1 RBAC | Finance SoD stated in role table; reviewer scope not formalised as REQ | `REQ-RBAC-4` Finance segregation; `REQ-RBAC-5` reviewer permission+scope; no role-name bypass | `WP01-E`, AGENTS §7 | WP-04 (Finance SoD also WP-03/05) | Documentation aligned to already implemented behaviour |
| §7.2 Documents | Business statuses only; no scan dimension; no current-row model | Business vs `scan_status`; exact snake_case statuses; stuck scan = pending beyond SLA; `REQ-DOC-6` current_marker / new-row resubmission | `SM-ADDENDUM-1`, `SM-DOC-1` | WP-03 | Documentation aligned to already implemented behaviour |
| §7.3 Reviewer | Reason labels only; no dual assignment; weak Application outcome detail | Exact reason codes; dual assignment (§7.3.1); Application/document transitions; VerificationAuditLog vs audit_log | `WP01-E`, REQ-REV-*, WP-04 note | WP-04 | Documentation aligned to already implemented behaviour |
| §8.1 Application | 11 statuses listed; Draft treated implicitly; Cancelled ambiguity in §18 only | `REQ-APP-1`–`REQ-APP-6`: factory Draft; SM-only changes; CourseVersion binding; edit rules; submit preconditions; WP-05/WP-06 ownership; Cancelled excluded | `APP-DRAFT-1`, WP-02/03 notes, AGENTS §6.1 | WP-02–WP-05 | Documentation aligned to already implemented behaviour |
| §8.2 Mode A narrative | Payment successful → Admitted without WP ownership | Notes WP-06 as authoritative for Successful/Admitted; WP-02–WP-05 Mode A chain | Roadmap WP-05/06; WP-05 Q1=A | WP-05 (handoff) | Documentation aligned to already implemented behaviour |
| §9.1 Payments | Implied single Payment-chain; statuses listed without matrix/ownership | Multi-attempt model; 10 statuses mapped to snake_case; snapshot; checkout flow; WP-06 handoff; no Authorized/Captured/Processing | `PAY-ATTEMPT-1`, AGENTS §6.3, WP-05 note | WP-05 | Documentation aligned to already implemented behaviour |
| §9.2 Pricing | Generic fee fields | Ties payable snapshot to Batch override / CourseVersion fee + GST in minor units | WP-05 / `PaymentAmountSnapshot` | WP-05 | Documentation aligned to already implemented behaviour |
| §9.5 Offline payments | Finance can mark offline paid | Clarifies WP-05 is Finance view-only; offline mark-paid not in WP-05 | WP-05 note | WP-05 | Documentation aligned to already implemented behaviour |
| §17.3 Data model | “at most one Payment-chain”; thin DocumentSubmission fields | Many Payment attempts; scan_status/current_marker; reviewer assignment entities; payment snapshot fields; PaymentStatusHistory | `PAY-ATTEMPT-1`, `SM-DOC-1`, `WP01-E` | WP-03–WP-05 | Documentation aligned to already implemented behaviour |
| §18.1 Application SM | Payment Pending → Cancelled in matrix despite 11-state enum | Matrix matches `ApplicationStateMachine`; Cancelled recorded as OD-APP-CANCELLED; Mode A implemented edges listed | AGENTS §6.1, WP-03 note | WP-03/04 | Documentation aligned to already implemented behaviour (discrepancy explicit, not invented) |
| §18.3 DocumentSubmission SM | Missing from formal SRS (Addendum only) | Full business transition matrix + queue-entry condition incorporated | `SM-ADDENDUM-1`, `SM-DOC-1` | WP-03/04 | Documentation aligned to already implemented behaviour |
| §18.4 Payment SM | Status list only | Full transition matrix matching `PaymentStateMachine`; WP-05 vs WP-06 ownership | AGENTS §6.3, WP-05 note | WP-05 | Documentation aligned to already implemented behaviour |
| §21 Audit | VerificationAuditLog vs AuditLog only | Adds payment_status_history; payload hygiene; atomic audit/history/outbox | AGENTS Rule 8; WP-03–WP-05 | WP-03–WP-05 | Documentation aligned to already implemented behaviour |
| §23 Open decisions | PRD carry-forward only | §23.1 genuine unresolved (Cancelled discrepancy, email/SMS, TL sign-offs, hosting/DR, WP-06); WP01-E not re-listed pending | Decision Log; WP notes | N/A | Documentation only |
| §24 Traceability | Absent | Matrix for APP-DRAFT-1, SM-ADDENDUM-1, SM-DOC-1, WP01-E, PAY-ATTEMPT-1, SRS-V61-1, WP-02…WP-06 handoff | `SRS-V61-1` | Docs | Documentation only |
| §25 WP-06 handoff | Absent / implied in roadmap | Explicit ownership list; WP-05 owns none of those mutations | Roadmap WP-06; WP-05 Q1=A | WP-06 not started | Documentation only |

## Explicit unresolved contradiction

| ID | Issue | Resolution |
| --- | --- | --- |
| OD-APP-CANCELLED | v6.0 §18.1 allowed Payment Pending → Cancelled; approved Application enum and implementation omit Cancelled | Deferred to a future product decision / SRS revision. Does **not** block Mode A WP-06. |

## Validation performed against merged code

1. Application statuses ↔ `ApplicationStatus` (11 constants; no `cancelled`)
2. Application transitions ↔ `ApplicationStateMachine::ALLOWED`
3. Document business + scan statuses ↔ `DocumentSubmissionStatus`, `DocumentScanStatus`
4. Document transitions ↔ `DocumentSubmissionStateMachine::ALLOWED`
5. Reason codes ↔ `DocumentRejectionReasonCode`
6. Payment statuses ↔ `PaymentStatus` (10 constants; no authorized/captured/processing)
7. Payment transitions ↔ `PaymentStateMachine::ALLOWED`
8. WP-06 behaviours documented as **not implemented**
9. `Academy_LMS_SRS_v6.md` MD5 unchanged from pre-edit baseline

## Files modified (expected)

- `docs/product/Academy_LMS_SRS_v6.1.md`
- `docs/product/SRS_V6_1_CONSOLIDATION_NOTE.md`
- `docs/README.md` (index pointer to current SRS)
