# Phase 1 demonstration script (25–35 minutes)

**Application URL:** `http://127.0.0.1:8080`  
**Login:** `http://127.0.0.1:8080/login`  
**Prepare:** `composer demo-prepare` (or `php bin/jobs.php demo:prepare --confirm`)  
**Process jobs:** `composer demo-process` after document upload/submit and after demo payment  

This script covers the **LMS Expansion Phase 1** customer journey: Course Admin authoring → Mode A admit → player → MCQ → certificate → public verify. Mode A admissions rules are unchanged (Enrolment only after Admitted; browser payment is never the source of truth).

## Personas and password

| Persona | Email | Opens on |
|---|---|---|
| Course Admin | `course-admin@uat.example.test` | Course Admin (`/admin/courses`) |
| Learner | `learner@uat.example.test` | My Applications (`/dashboard`) — **Active** Phase 1 enrolment ready |
| Certificate scenario | `learner-phase1-complete@uat.example.test` | Dashboard — completed pathway + issued certificate |
| Reviewer | `reviewer@uat.example.test` | Reviewer Queue |
| Finance | `finance@uat.example.test` | Reconciliation (optional SoD) |
| Notification Ops | `ops@uat.example.test` | Notifications via nav (`/admin/notifications`) |

**Password:** environment `UAT_SEED_PASSWORD`, else `Uat-Demo-Passw0rd!`.  
`demo:prepare` prints the exact password in use.

## Seeded Phase 1 course (use this for learning)

| Field | Value |
|---|---|
| **Course** | Phase 1 Demo — Obesity Learning Pathway (`PHASE1-DEMO-CME-101`) |
| **Slug** | `/courses/phase1-demo-obesity-learning` |
| **Curriculum** | Module 1: two text lessons · Module 2: MCQ knowledge check (5 questions, pass 60%) |
| **Batch** | Phase 1 demo cohort (active learning) — `starts_at` in the past → Admit creates **Active** enrolment |
| **Learner progress** | `learner@` has lesson 1 completed; lesson 2 + MCQ still available |
| **Certificate scenario** | `learner-phase1-complete@` has all items complete + issued certificate |

**Also seeded (Mode A catalogue):** Certificate Course in Obesity and Metabolic Health (`WP02-DEMO-OBESITY-101`) with open/upcoming/closed batches — useful for admissions discussion. Prefer the **Phase 1 Demo** course for player/assessment/certificate beats.

---

## Sequence (live journey)

### Minute 0–2 — Frame the demo

Narrative:

- This is the real Academy LMS application, not a clickable mock.
- Mode A: documents reviewed before payment; Enrolment is created only when Application is Admitted.
- Browser payment return never marks success; webhook + worker (`demo:process`) do.
- Phase 1 adds Course Admin curriculum, learner player, MCQ attempts, and completion certificates.

### Minute 2–8 — Course Admin: existing Phase 1 course

1. Sign in as **Course Admin**.
2. Open **Course Admin** → confirm **Phase 1 Demo — Obesity Learning Pathway** is in scope.
3. Open the published version → **Open curriculum**.
4. Show Module 1 text lessons and Module 2 MCQ item.
5. Open **Question bank** (from the course page) and show MCQ stems (correct flags only here).
6. Open **Configure assessment** on the MCQ content item (pass %, linked questions).
7. Optional: show **Create batch** / existing active batch; do not invent new product features.

If the customer asks to *create* from scratch: New course → curriculum → bank → assessment → Publish → batch with **start date today or earlier**.

### Minute 8–10 — Public catalogue

1. Sign out (or guest window).
2. Open `/courses` → **Phase 1 Demo — Obesity Learning Pathway**.
3. Point out fee, eligibility, document requirement, open batch, Apply.

### Minute 10–20 — Learner Mode A (optional live admit)

**Fast path (recommended for time):** skip live admit; use seeded `learner@` Active enrolment (next section).

**Full Mode A path:**

1. Sign in as **Learner** (or register a new user).
2. Apply to the Phase 1 demo batch → upload documents → submit.
3. Run `composer demo-process`.
4. As **Reviewer**, claim the live application → approve docs → send to payment.
5. As **Learner**, **Complete demo payment** → screen shows **Confirming payment…**.
6. Run `composer demo-process` again → dashboard shows **Admitted** + **Active** enrolment + **Continue learning**.

### Minute 20–26 — Player + progress

1. As **Learner** (`learner@`), open **Continue learning** on the Phase 1 enrolment.
2. Outline shows Module 1 / Module 2; lesson 1 already completed (seeded progress).
3. Open lesson 2 → **Mark complete**.
4. Confirm outline progress updates; MCQ unlocks when prior mandatory items are done.

### Minute 26–30 — Assessment

1. Open **Module knowledge check** → **Start attempt**.
2. Answer questions (correct options are the first choice in the seeded bank).
3. **Submit attempt** → show score / pass.
4. Outline shows assessment completed when passed.

### Minute 30–33 — Certificate + public verify

**Path A — finish with `learner@`:** after all mandatory items complete, open **Certificates** from the outline → view certificate → **Download PDF** / **Print** → open **Public verification link** (logged out).

**Path B — seeded certificate scenario:** sign in as `learner-phase1-complete@uat.example.test` → Continue learning → **Certificates** → show issued certificate → copy public verify URL → open in a logged-out window.

Public verify shows: validity, learner name, course, type, issue date — **no email, phone, or address**.

### Minute 33–35 — Close / optional SoD

- Finance login cannot open credential document URLs.
- Re-run `demo:process` is safe (no duplicate Enrolment).

---

## Fallback scenarios (Mode A catalogue)

| Application | State | Use |
|---|---|---|
| `UAT-REVIEW-001` | Under review | Reviewer queue |
| `UAT-CONFIRM-001` | Payment pending | Confirming payment screen |
| `UAT-ADMIT-ACTIVE-001` | Admitted + Active on obesity catalogue course | Admissions only — **no Phase 1 curriculum** on that course |
| `UAT-PHASE1-LEARN-001` | Learner Phase 1 Active + partial progress | Primary learning demo (`learner@`) |
| `UAT-PHASE1-CERT-001` | Complete + certificate | Certificate / verify demo |

Scenario learners use `learner-{slug}@uat.example.test` (same password), except the Phase 1 complete persona above.

---

## Features not in this Phase 1 demo (say this clearly)

- Video lessons / Mux streaming
- PDF learning file download (type exists; media delivery later)
- Certificate designer, revoke UI, QR
- Timers / randomised papers / proctoring
- Refunds automation
- Production SES / S3 / Razorpay (local demo uses fake/local adapters)
- MFA challenge UI for privileged roles (production gap)

---

## Questions to ask users

1. Was the next step always obvious after each screen?
2. Did “Confirming payment…” make the payment trust model clear?
3. Was the path from lessons → assessment → certificate understandable?
4. Would reviewers trust this queue for real credential work?
5. What would block you from recommending a pilot with a real cohort?

Capture answers in [`USER_FEEDBACK_TEMPLATE.md`](./USER_FEEDBACK_TEMPLATE.md).

---

## Related docs

- [`PHASE1_CUSTOMER_DEMO_ACCEPTANCE_CHECKLIST.md`](./PHASE1_CUSTOMER_DEMO_ACCEPTANCE_CHECKLIST.md)
- [`PHASE1_DEMO_READINESS_AUDIT.md`](./PHASE1_DEMO_READINESS_AUDIT.md)
- [`DEMO_READINESS_CHECKLIST.md`](./DEMO_READINESS_CHECKLIST.md)
- [`README.md`](./README.md)
