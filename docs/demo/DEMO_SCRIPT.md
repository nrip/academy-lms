# Mode A demonstration script (15–20 minutes)

**Application URL:** `http://127.0.0.1:8080`  
**Login:** `http://127.0.0.1:8080/login`  
**Prepare:** `php bin/jobs.php demo:prepare --confirm`  
**Process jobs:** `php bin/jobs.php demo:process`

## Personas and password

| Persona | Email | Opens on |
|---|---|---|
| Learner | `learner@uat.example.test` | My Applications (`/dashboard`) |
| Reviewer | `reviewer@uat.example.test` | Reviewer Queue |
| Finance | `finance@uat.example.test` | Reconciliation |
| Notification Ops | `ops@uat.example.test` | Reviewer Queue first (Super Admin precedence); open **Notifications** in nav → `/admin/notifications` |

**Password mechanism:** environment `UAT_SEED_PASSWORD`, else documented default `Uat-Demo-Passw0rd!`.  
`demo:prepare` prints the exact password in use. These are fictional UAT/demo identities only.

**Course:** Certificate Course in Obesity and Metabolic Health (`WP02-DEMO-OBESITY-101`)  
**Open batch:** March 2027 cohort (`WP02-DEMO-OBESITY-101-OPEN`)

---

## Sequence (live journey)

### Minute 0–2 — Frame the demo

Narrative:

- This is the real Academy LMS application, not a clickable mock.
- Mode A: documents reviewed before payment; Enrolment is created only when Application is Admitted.
- Browser payment return never marks success; webhook + worker do.

### Minute 2–7 — Learner applies

1. Open `/login`, sign in as **Learner**.
2. Confirm nav: Courses · My Applications · Profile · Logout.
3. Open **Courses** → open **Certificate Course in Obesity and Metabolic Health**.
4. Point out description, audience, duration, eligibility, fee+GST, document requirements, open batch and capacity.
5. Open batches → select open batch → **Apply** / create Application.
6. Complete profile/qualification fields if prompted.
7. Upload a clean PDF for required documents (any small PDF is fine).
8. Submit the Application.
9. Say: “Documents must be scanned before they reach the reviewer queue.”

In a second terminal:

```bash
php bin/jobs.php demo:process
```

Expected: Application moves toward review; learner sees status such as Under review / Submitted.

### Minute 7–11 — Reviewer decides

1. Logout → login as **Reviewer**.
2. Confirm landing is Reviewer Queue; nav shows Reviewer Queue · Logout (plus any other authorized items).
3. Open the learner’s Application by matching the **application reference** shown on My Applications
   (e.g. `APP-…`). Do **not** open seeded `UAT-REVIEW-001` unless the live queue has no learner-created row —
   that seeded marker is a different application and will not show the learner’s uploads.
4. Claim the Application.
5. Review documents → Approve documents.
6. Approve Application into **Payment required** / `payment_pending`.

Narrative: Finance never sees these documents; segregation of duties is intentional.
After uploads, the learner must **Submit** the application before it appears in the reviewer queue.
Scan-pending documents remain visible on the detail page; Verify stays disabled until scan is clean.
Run `php bin/jobs.php demo:process` (or `composer demo-process`) after upload/submit so scans complete.

### Minute 11–15 — Learner pays (demo capture)

1. Logout → login as **Learner** again.
2. Open the Application → **Pay now**.
3. Initiate payment if needed.
4. On the payment attempt page, click **Complete demo payment**.
5. Expect **Confirming payment…** — emphasise this is not “Successful”.
6. Run:

```bash
php bin/jobs.php demo:process
```

7. Refresh payment status / My Applications.
8. Expect Admitted + Enrolment (Scheduled or Active depending on batch dates).

Narrative: The button only creates a signed Razorpay-shaped webhook; workers perform acceptance and Enrolment.

### Minute 15–17 — Finance

1. Login as **Finance**.
2. Open **Payments** — find the successful payment.
3. Open **Reconciliation** — mention under-verification / seeded reconciliation cases (`UAT-AWAIT-001`, `UAT-FULLBATCH-001`, `UAT-DUP-001`).
4. Attempt any document URL if shown in notes — Finance must be denied document access.

### Minute 17–20 — Notifications + wrap

1. Login as **Notification Ops** (`ops@uat.example.test`).
2. Super Admin permission precedence may land on Reviewer Queue — open **Notifications** in the nav (`/admin/notifications`).
3. Open Notifications — show masked recipients (`lea***@uat.example.test`).
4. Open a failed/retryable sample (`uat.retryable`) and retry if the UI allows.
5. Summarise what is in scope vs not (below).
6. Ask feedback questions (below).

---

## Fallback ready-made scenarios

If time is short or live path stalls, log in as the scenario owner or inspect as reviewer/finance:

| Marker | State | Discussion point |
|---|---|---|
| `UAT-DRAFT-001` | Draft | Incomplete application |
| `UAT-REVIEW-001` | Under review | Queue / claim |
| `UAT-CORRECT-001` | Correction required | Resubmission |
| `UAT-PAYPEND-001` | Payment pending | Approved, awaiting pay |
| `UAT-CONFIRM-001` | Payment pending (in-flight) | Confirming UI |
| `UAT-AWAIT-001` | Awaiting verification | Payment under verification |
| `UAT-ADMIT-SCHED-001` | Admitted + Scheduled Enrolment | Post-admission before batch start |
| `UAT-ADMIT-ACTIVE-001` | Admitted + Active Enrolment | Learning access later |
| `UAT-REJECT-001` | Rejected | Mode A rejection (no Enrolment) |
| `UAT-DUP-001` | Admitted + duplicate reconciliation row | Duplicate capture discussion |
| `UAT-FULLBATCH-001` | Payment pending + capacity reconciliation | Full batch after capture |

Scenario learners: `learner-{slug}@uat.example.test` (same password).

---

## Features not yet implemented (say this clearly)

- Course player / lessons / modules
- Assessments
- Certificate generation / public verify beyond existing stubs
- Course Admin builder UI
- Refunds
- Production email/SMS providers
- Production hosting / monitoring / backup automation

---

## Questions to ask users

1. Was the next step always obvious after each screen?
2. Did “Confirming payment…” make the payment trust model clear?
3. Would reviewers trust this queue for real credential work?
4. What language felt clinical vs confusing?
5. What would block you from recommending a pilot with a real cohort?
6. Which ready-made scenario matched a case you see today?

Capture answers in [`USER_FEEDBACK_TEMPLATE.md`](./USER_FEEDBACK_TEMPLATE.md).
