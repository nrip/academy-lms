# Flaky Test Register — Academy LMS

**Purpose:** Track intermittent automated test failures without hiding regressions.  
**Policy (RC-01 Y):**

| Observation | Action |
|---|---|
| One isolated failure + clean rerun | **Observe** — record here; do not change the test solely for one flake |
| Repeated occurrence | **Investigate and stabilize** |
| CI auto-retry of individual failing tests | **Forbidden** if it would hide regressions (no silent per-test retry) |

Concurrency cancellation for superseded workflow runs is a separate CI concern and does not replace this register.

---

## Active entries

### FLAKE-001 — WebhookAdmissionConcurrencyTest::testTwoApplicationsRacingFinalBatchSeat

| Field | Value |
|---|---|
| **Test** | `WebhookAdmissionConcurrencyTest::testTwoApplicationsRacingFinalBatchSeat` |
| **First observed** | 2026-07-24 |
| **Symptom** | Intermittent CI failure (batch seat race / admission concurrency assertion) |
| **Rerun result** | Passed on rerun |
| **Current status** | **Observe** |
| **Trigger for investigation** | Any **repeat** failure on main/CI for the same test (second independent incident) |
| **Notes** | Do not weaken seating/admission assertions to “make CI green”. Prefer deterministic locking fixtures or barrier synchronization if investigation starts. |
| **Owner** | Engineering (payments / admissions) |
| **Last updated** | 2026-07-24 |

---

## Resolved / historical

_None yet._

| ID | Test | Resolved on | Resolution |
|---|---|---|---|
| | | | |

---

## How to add an entry

1. Copy the Active entry template.
2. Set status to `Observe` or `Investigating`.
3. Link CI run URLs in Notes (no secrets).
4. Promote to Investigating on repeat; close to Resolved with root cause when fixed.
