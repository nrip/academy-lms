# RC1 demo environment plan

**Status:** Plan only. Do not treat this as implemented.  
**Host intent:** The Hetzner demonstration host is one combined playground, not a separate guided-demo machine. This document remains the seeded showcase script. The host model is [`ACADEMY_DEMO_PLAYGROUND_PLAN.md`](./ACADEMY_DEMO_PLAYGROUND_PLAN.md).  
**Date:** 2026-09-14  
**Audience:** Facilitator and the engineer who will stand up the host.  
**Goal:** A customer understands the complete Academy platform in a **30–45 minute guided demo**.

This is a **demonstration host**, not the production rollout. It must not share a database, object store, payment account, or mailbox with a live academy.

**Related (do not replace):**

- Local Mode A / Phase 1 run: [`../demo/README.md`](../demo/README.md), [`../demo/DEMO_SCRIPT.md`](../demo/DEMO_SCRIPT.md)
- Personas and reset: [`../uat/UAT_ACCOUNTS.md`](../uat/UAT_ACCOUNTS.md)
- RC1 experience (implemented on `main`): [`../product/RC1_IMPLEMENTATION_PLAN.md`](../product/RC1_IMPLEMENTATION_PLAN.md)
- Host shape: [`HETZNER_VPS_DEPLOYMENT_GUIDE.md`](./HETZNER_VPS_DEPLOYMENT_GUIDE.md), [`SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md`](./SINGLE_CUSTOMER_DEPLOYMENT_GUIDE.md)
- Email: [`EMAIL_CONFIGURATION.md`](./EMAIL_CONFIGURATION.md)

---

## What this demo can and cannot claim

RC1 slices 1–5 are in the application. The **current seed and demo commands** were built for Mode A plus Phase 1 learning. They do not yet stage every RC1 surface (course covers, a live lesson, a clean applicant, or the four branded letters as real sends).

The 30–45 minute script below uses what seed and commands already do, and names the gaps the facilitator must not paper over.

| Already seedable | Not in current seed — do not invent on the day |
|---|---|
| Published catalogue course and Phase 1 learning course | Course cover images |
| Eligibility rules and required documents on those editions | A separate Faculty role or faculty names |
| Learner already admitted on the Phase 1 course, with partial progress | A clean applicant who can apply to that same course |
| Completed learner with an issued certificate | Certificate email from that seeded certificate |
| Reviewer queue, finance denial, course-admin scope | Upcoming live lessons (faculty home will be empty of them) |
| Fake payment, fake scanner, local documents, captured mail files | Production Razorpay, S3, or SES |

Customer language in the room: **Course, Edition, Chapter, Lesson, Quiz**. Do not say ContentItem, CourseVersion, object keys, or storage drivers.

---

## 1. Demo infrastructure requirements

Stand up a **dedicated host**. Do not point this at customer production data. Do not run `demo:prepare`, `uat:seed`, or `uat:reset` on `APP_ENV=staging` or `APP_ENV=production`. Those commands, the catalogue seeder, the Phase 1 seeder, and fake payment all refuse those environments.

| Item | Requirement | Why |
|---|---|---|
| Isolation | Own VPS, own database, own `.env` | Seed and reset delete `@uat.example.test` data |
| `APP_ENV` | `uat` | Only `local`, `testing`, `ci`, and `uat` allow seed, reset, and `demo:prepare` |
| Runtime | PHP **8.4**, MySQL 8.x, Nginx (or the documented PHP server for a local rehearsal) | Stack in AGENTS.md |
| Size | Same trial baseline as the Hetzner guide: prefer ≥ 2 vCPU / 4 GB RAM / 40 GB | Dompdf and MySQL share the box |
| HTTPS | Public hostname with a certificate | A customer should not demo over plain HTTP. `APP_URL` must be that origin |
| PHP uploads | `upload_max_filesize` ≥ `10M`, `post_max_size` ≥ `16M` | Credential documents are 10 MB. `demo:prepare` refuses a smaller limit |
| Disk | Writable document root and `NOTIFICATION_LOCAL_MAIL_PATH` | Documents and captured letters stay on this host |
| Workers | Cron for `php bin/jobs.php demo:process` every minute during the demo window | Scan, webhook processing, and mail delivery otherwise stall the room |
| Access | SSH for the facilitator only. No customer shell | Reset is destructive |
| Data | Synthetic seed only. No learner PII, no production dumps, no live Razorpay keys | AGENTS.md Rule 11 |

MySQL stays on the same server for this host, matching the trial guides. Do not attach Hetzner Object Storage or a production S3 bucket and describe it as document storage. This application’s demo path requires `DOCUMENTS_STORAGE_DRIVER=local`.

**Not this host:** the first-customer trial guides that set `APP_ENV=production` and forbid `demo:prepare`. Those remain the production path. This plan is the other machine.

Open hostname, DNS, and who holds the SSH key are not specified in the SRS. Fill them in the go-live checklist before the session. Do not invent a domain here.

---

## 2. Required environment configuration

Copy `.env.example`. The values below are what `demo:prepare` already enforces. Do not relax them to look more like production.

```bash
APP_ENV=uat
APP_DEBUG=false
APP_URL=https://<demo-host>
# APP_FORCE_HTTPS: set true only if the reverse proxy terminates TLS and the app is reached only over HTTPS

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=academy_lms_demo
DB_USER=academy_demo
DB_PASSWORD=<host-only secret>

PAYMENTS_FAKE_GATEWAY=1
DOCUMENTS_STORAGE_DRIVER=local
DOCUMENTS_FAKE_SCANNER=1
NOTIFICATION_EMAIL_ADAPTER=local_file
NOTIFICATION_LOCAL_MAIL_PATH=storage/mail

# Do not commit. Rotate if the host is shared beyond the facilitator.
UAT_SEED_PASSWORD=<demo password>
```

| Setting | Must be | Must not be |
|---|---|---|
| `APP_ENV` | `uat` | `staging`, `production` |
| `PAYMENTS_FAKE_GATEWAY` | `1` | Real Razorpay keys presented as checkout |
| `DOCUMENTS_STORAGE_DRIVER` | `local` | `s3` / unconfigured |
| `DOCUMENTS_FAKE_SCANNER` | `1` | A claim that malware scanning is production-grade |
| `NOTIFICATION_EMAIL_ADAPTER` | `local_file` (or `recording` if nobody will open files) | Omitted — on `uat` the default is `unavailable`, and `demo:prepare` then refuses |
| `APP_DEBUG` | `false` on a host a customer can reach | Stack traces in the room |

`demo:prepare` fails unless payment is the fake gateway, documents are local, the scanner is fake, and email is `local_file` or `recording`. That is intentional. A browser “success” is still not payment confirmation: the fake path writes a signed webhook and the worker settles it.

### Email: captured letters vs a customer inbox

The supported demonstration of the four branded letters is **captured `.eml` files** under `NOTIFICATION_LOCAL_MAIL_PATH`. `demo:prepare` will not run if the adapter is SMTP.

Showing a letter in the customer’s own inbox is **not supported by the current seed gate**. Do not switch the running host to `MAIL_DRIVER=smtp` during the session unless that path has been verified in a later change. Staging and production reject `local_file`; this host is `uat` so that capture remains legal.

The facilitator opens the captured file in a mail client or browser on the demo laptop. Say plainly: this host records the letter the academy would send; it is not a live mailbox.

### Payments

Checkout uses `FakePaymentGateway`. The learner action is **Complete demo payment**. The next screen is **Confirming payment…**. Settlement is `demo:process` (or `demo:payment-capture {paymentId}`), not the browser return.

Do not configure Razorpay on this host. Do not describe the button as a live charge.

### Privileged login

Seeded reviewer, finance, course admin, and super admin have **no MFA device**. AGENTS.md §7.3 makes those roles MFA-mandatory. UAT accounts documentation: first privileged login stops at MFA enrolment until TOTP is verified.

Before the customer arrives, the facilitator enrols TOTP for each persona they will open, stores recovery codes in session notes only, and keeps those sessions (or allows time to re-enrol). Do not disable MFA. Do not put codes in the defect log or git.

Learner routes do not require MFA.

---

## 3. Demo users by role

Password: `UAT_SEED_PASSWORD`, otherwise the documented default `Uat-Demo-Passw0rd!`. `demo:prepare` prints the password in use. These addresses are synthetic (`uat.example.test`). They are not production accounts.

| Persona in the room | Login | Role in the product | Opens on | Use in the 45 minutes |
|---|---|---|---|---|
| Learner (already studying) | `learner@uat.example.test` | Applicant | `/dashboard` | Progress, continue, quiz, inbox. **Cannot re-apply** to the Phase 1 course |
| Certificate example | `learner-phase1-complete@uat.example.test` | Applicant | `/dashboard` | Issued certificate and public verify. **Does not** prove the certificate email |
| New applicant | Register during prep, or live | Applicant | `/dashboard` | Only path that can apply to the Phase 1 course. See §5 |
| Reviewer | `reviewer@uat.example.test` | Credential Reviewer | `/reviewer/applications` | Queue, document decision, send to payment |
| Course Admin / Faculty | `course-admin@uat.example.test` | Course Administrator | `/admin/courses` | Counts, eligibility, documents, and Faculty home. **One user. There is no Faculty role** |
| Finance (short) | `finance@uat.example.test` | Finance Administrator | `/finance/reconciliation` | Payments exist; documents do not. No revenue on Course Admin |
| Notification ops (optional) | `ops@uat.example.test` | Super Admin | Reviewer queue, then Notifications in the nav | Delivery list. Skip if time is tight |

Also seeded, not needed in the guided hour: `multi@uat.example.test`, and scenario applicants `learner-{slug}@uat.example.test` for draft, under review, resubmission, payment pending, confirming payment, awaiting verification, admitted, rejected, duplicate capture, and capacity-after-payment. Application numbers use the `UAT-` prefix (`UAT-REVIEW-001`, `UAT-CONFIRM-001`, and so on). See [`../uat/UAT_ACCOUNTS.md`](../uat/UAT_ACCOUNTS.md).

Course Admin is scoped to the demo courses via `course_admin_scope_assignments`, including future editions of those courses. Reviewer is scoped to the demo open batch.

---

## 4. Demo courses and catalogue data

`demo:prepare` runs two seeders, then UAT personas.

### Course A — learning journey (use this)

| | |
|---|---|
| Title | Phase 1 Demo — Obesity Learning Pathway |
| Code | `PHASE1-DEMO-CME-101` |
| Public URL | `/courses/phase1-demo-obesity-learning` |
| Chapters | Foundations of metabolic health; Assessment and completion |
| Lessons | Embedded video lesson, reading lesson, quiz (5 questions, pass 60%) |
| Batch | `PHASE1-DEMO-CME-101-ACTIVE` — start date in the past, so admit creates an **Active** enrolment |
| Learner state | `learner@` has the first lesson complete; reading and quiz remain |
| Certificate state | `learner-phase1-complete@` has the pathway complete and a certificate row |
| Eligibility and documents | Seeded on the edition. The edition is **published and locked**. Changing them in the room requires the next edition |

Say “Chapter” and “Lesson” in the room. The video is an embedded public URL already in the seeder, not a Mux asset.

### Course B — catalogue and admissions shapes

| | |
|---|---|
| Title | Certificate Course in Obesity and Metabolic Health |
| Code | `WP02-DEMO-OBESITY-101` |
| Open batch | `WP02-DEMO-OBESITY-101-OPEN` |
| Fee shown by seed | Standard fee `15000.00` (plus GST as stored on the edition) |
| Queue samples | `UAT-REVIEW-001` and the other `UAT-*` applications sit on this catalogue course |

A second seeded catalogue title exists (`Advanced Metabolic Health Management`). Do not add partner hospitals, CME credit hours, or learner counts. The storefront has no featured flag; every published course appears. Do not rank or hide one to “feature” it.

### What the public page already shares with admin

Eligibility and required documents on the public course page, the learner checklist, and the reviewer checklist are the same configured source. On a **locked** seeded edition, Course Admin can show that screen but cannot save. To demonstrate editing, use an **unlocked** next edition created before the session — and do not publish it until the edit is done. Publishing locks the edition.

Course covers are stored on the course, not the edition. **Neither seeder sets a cover.** Before the session, upload one synthetic cover as Course Admin, or tell the customer the card uses the placeholder. Do not drop a production image into the demo.

---

## 5. Learner journey (about 12 minutes)

Narrative: a doctor or nurse finds a course, applies, is admitted only after review and a confirmed payment, then studies and can earn a certificate. Progress on the dashboard is lessons completed out of lessons in the outline. It is not “course complete”, and it is not certificate eligibility.

### Prefer this order

| Minute | What the customer sees | How, with today’s seed |
|---|---|---|
| 0–3 | Storefront `/` and catalogue `/courses` | Guest window. Headline, published courses, no invented counts |
| 3–6 | Course page: edition, eligibility, required documents, fee, Apply | Phase 1 course. If no cover was uploaded, say so |
| 6–8 | Dashboard of someone already admitted | `learner@`. Continue learning, upcoming-session area (likely empty), inbox |
| 8–12 | Player: complete the reading, show the quiz | Do not submit the quiz here if minute 8–12 is already tight; the assessment beat is in the close of this block or after reviewer |

### Live apply (only if the new applicant was prepared)

`learner@` already has an application on the Phase 1 course and **cannot apply again**. The seed has no unused applicant aimed at that course.

Preparation, before the customer is in the room:

1. Register a new account (synthetic name, synthetic email).
2. Open the verification letter in `storage/mail` and verify. The learner must not be shown a raw token as body text; the letter’s button is the path. The plain-text fallback still contains the URL.
3. Leave them **without** an application on the Phase 1 course.

In the room, that account applies, uploads a small synthetic PDF, and submits. Then run `demo:process` (or wait for the cron) so the scan finishes before the reviewer opens the queue. Without that, the queue looks stuck.

Registration verification mail is **not** copied to the learner inbox. That is current behaviour, not a failed send.

### Do not live-apply to Course B’s open batch for the learning story

That batch’s start date is in the future. Admit there creates a **Scheduled** enrolment and there is no Continue learning. Use it only if the customer asks about a course that has not started.

---

## 6. Admin journey (about 6 minutes)

Sign in as `course-admin@uat.example.test`.

1. **Course Admin** (`/admin/courses`): four counts — courses, published courses, active batches, learners enrolled. There is **no payment or revenue card**. If asked for collections, that is Finance, and product has not assigned revenue to Course Admin.
2. Open the Phase 1 course. Language is edition, chapter, lesson. Publishing is described as locking the edition.
3. Open eligibility and required documents. On the seeded published edition, show the configured categories (Doctor, Nurse, Allied medical professional) and the document list, and say the page is locked. If a next edition was prepared, edit a document name or note there, then show that the public page reads the same source. Do not claim the academy blocks Apply by profession — that check is not implemented. Profile profession is free text.
4. Optional, if they ask how a course is built: chapters, lessons, quiz, question bank. Do not create a course from scratch in the 45 minutes.

Course Admin cannot approve credential documents. Do not open the reviewer queue with this user and expect an approve action.

---

## 7. Faculty journey (about 4 minutes)

There is **no Faculty role** and no second login. Faculty home is the same assignment as Course Admin: `course.view_assigned` plus the course scope already seeded for `course-admin@`.

1. Open **Faculty** (`/faculty`) with that user.
2. Show assigned courses and learner counts.
3. Say what is absent, rather than clicking around: no upcoming live lesson (the Phase 1 seed has none), no join URL, no application numbers, no payments.
4. A recent admission or lesson update appears only if one was just made (live admit, or the learner marked a lesson complete). If the list is empty, say it fills from those events. Do not insert a row by hand during the demo.

A named faculty member, a hospital affiliation, or a faculty inbox is out of RC1. Do not demo them.

---

## 8. Reviewer journey (about 6 minutes)

Sign in as `reviewer@uat.example.test` (MFA already enrolled).

**Fast path (fits 45 minutes):** open `UAT-REVIEW-001` (under review on the catalogue course). Show the queue, the document checklist from the course’s required documents, a decision, and that Finance cannot do this job.

**Live path (only with the prepared applicant):** after scan has completed, claim the new application, approve documents, send to payment.

Then, as the applicant:

1. Pay with **Complete demo payment**.
2. Show **Confirming payment…**. Say the browser did not confirm money.
3. Let `demo:process` run (cron, or the facilitator runs it). Reload. Status becomes Admitted and an enrolment exists. On the Phase 1 batch that enrolment is Active and Continue learning appears.

Do not mark a payment successful from the browser. Do not create an enrolment any other way.

Reviewer cannot issue or approve a refund. Do not open a refund screen with this user.

If the queue does not show the new application, the usual cause is the scan worker. Run `demo:process` and refresh. Do not change the application status in the database.

---

## 9. Email demonstration flow (about 5 minutes)

Four letters are branded HTML with a plain-text alternative. Same academy header and footer. The inbox stores the **plain text** after a successful send, one row per outbox message. A retry does not add a second row. A failed send does not add a row.

| Letter | Subject | Existing trigger | Inbox |
|---|---|---|---|
| Registration verification | Verify your Academy account | Register or resend verification | No — token mail is excluded |
| Application received | Your application has been received | Application submitted | Yes, after the send succeeds |
| Admission confirmation | Congratulations! You have been admitted | Application admitted | Yes. Button: Start learning |
| Certificate ready | Your certificate is ready | Certificate issued by the existing issuance path | Yes. Certificate action |

Also sent, and not one of the four: “Your course is ready” when the enrolment is created. Do not merge that with the admission letter in the script. Product has not approved combining them.

### How to show them without waiting on a full live journey

The seeded certificate **does not** write an outbox message. Opening `learner-phase1-complete@` does not prove the certificate letter.

Before the session, with the host already on `local_file`:

1. Register and verify — keep that `.eml` (letter 1).
2. Submit an application and run `demo:process` — keep “Your application has been received” (letter 2).
3. Reviewer approves and the applicant completes demo payment; `demo:process` admits — keep “Congratulations! You have been admitted” (letter 3). Optionally show the separate “Your course is ready” file and say it is a second message today.
4. Complete the learning requirements so issuance runs, then `demo:process` — keep “Your certificate is ready” (letter 4).

In the room, open those four files. If time allows, submit one application live and refresh the learner inbox after delivery so they see the in-app copy match the letter’s plain text, not the HTML.

Say what is not built: no template editor, no newsletter, no campaign. SVG logos may not render in some mail clients. The verify and reset links still carry a token in the URL because those routes require it; the HTML must not display the token as visible text.

---

## 10. Reset and reseed procedure

Reset deletes seeded demo users and their applications. It does not roll back migrations. It is refused unless `APP_ENV` is `local`, `testing`, `ci`, or `uat`.

**When:** the morning of a customer session, or after a session that created extra applications. Not during the call.

```bash
php bin/jobs.php uat:reset --confirm
php bin/jobs.php demo:prepare --confirm
```

Use `--migrate` only when this host is behind the schema:

```bash
php bin/jobs.php demo:prepare --confirm --migrate
```

`demo:prepare` is safe to repeat. It reprints the URL, persona emails, and password.

### After reseed, expect the clock to start again

| Restored | Lost |
|---|---|
| Both demo courses, personas, `UAT-*` applications, Phase 1 progress, seeded certificate row | MFA devices the facilitator enrolled |
| Locked published editions | Any next edition, cover, or edit made in the room |
| | Any applicant who is **not** `@uat.example.test` is not removed by the domain delete — remove those accounts deliberately if they must not survive |
| | Captured `.eml` files on disk (delete `storage/mail` contents if the last session’s letters must not appear) |
| | The four branded letters, unless regenerated (the certificate seed still does not send mail) |

The Phase 1 learning seeder does not rebuild a course that already exists; it only ensures the active batch. A full `uat:reset` plus `demo:prepare` is the supported return to the scripted state. Do not hand-edit statuses to “reset”.

`learner@` is admitted again after reseed. Reseed does **not** create a clean Apply path on the Phase 1 course. Repeat the new-applicant preparation in §5 after every reset.

Then re-enrol MFA for reviewer, course admin, and finance before the customer joins.

---

## 11. Go-live checklist

This checklist means **the demo host is ready for a customer session**. It is not a production go-live. Production still needs real Razorpay, private object storage and scanning, production SMTP, supervised workers, MFA already in the customer’s hands, and the open items in [`../product/PRODUCTION_READINESS_REGISTER.md`](../product/PRODUCTION_READINESS_REGISTER.md).

### Host

- [ ] Dedicated server and database. Not the production academy host.
- [ ] `APP_ENV=uat`, `APP_DEBUG=false`, `APP_URL` is the public HTTPS origin.
- [ ] PHP 8.4; upload limits 10M / 16M.
- [ ] TLS certificate valid for the demo hostname.
- [ ] Firewall: 80/443 public; MySQL not public; SSH restricted.
- [ ] No production secrets, Razorpay live keys, or learner data in `.env` or the database.

### Seed

- [ ] `php bin/jobs.php demo:prepare --confirm --migrate` succeeded on this host.
- [ ] Output lists Learner, Reviewer, Finance, Course Admin, Notification Operations.
- [ ] `PAYMENTS_FAKE_GATEWAY=1`, local documents, fake scanner, `NOTIFICATION_EMAIL_ADAPTER=local_file`.
- [ ] `/courses/phase1-demo-obesity-learning` returns the Phase 1 course.
- [ ] `/courses` shows published courses only. No invented featured ranking.
- [ ] Cover uploaded, or the facilitator’s notes say the placeholder is expected.
- [ ] New applicant prepared if the script includes live Apply. `learner@` is not that person.
- [ ] MFA enrolled for every privileged persona the script opens. Recovery codes in session notes only.

### Jobs and mail

- [ ] Cron runs `demo:process` during the demo window, **or** the facilitator has a tested SSH command and will use it.
- [ ] A test upload reaches the reviewer queue after that worker runs.
- [ ] A test demo payment moves from Confirming payment… to Admitted only after the worker, and a second run does not create a second enrolment.
- [ ] Four `.eml` files captured and opened once on the demo laptop (subjects in §9).
- [ ] Learner inbox shows the application-received plain text, and does not show the verification token mail.

### Script rehearsal (do this once, timed)

- [ ] Guest storefront and course page (3 minutes).
- [ ] Learner dashboard, continue, one lesson (6 minutes).
- [ ] Reviewer queue on `UAT-REVIEW-001` (4 minutes).
- [ ] Course Admin counts, locked eligibility page, Faculty home (8 minutes).
- [ ] Certificate on `learner-phase1-complete@` and public verify with no contact details (4 minutes).
- [ ] Four letters (5 minutes).
- [ ] Finance cannot open a document (1 minute).
- [ ] Total with a live apply and demo payment: still inside 45 minutes, including one worker wait.

### In the room — say these limits out loud

- This is the real application on a demonstration host, not a clickable prototype and not the production academy.
- Payment on this host is simulated. The confirming screen is not a receipt. Production confirmation is a verified webhook.
- Documents and scanning on this host are local and simulated.
- Mail is captured on the host so the letter can be shown. It is not the customer’s production mailbox.
- Enrolment appears only after Admitted.
- A published edition cannot be edited; the next edition is the change path.
- There is no Faculty role, no Q&A, no forum, and no email template editor in this release.

### After the session

- [ ] `uat:reset --confirm` and `demo:prepare --confirm` if another customer will use the host.
- [ ] Delete captured mail if it contains the session’s demo addresses.
- [ ] Do not copy the demo database into production.

---

## Suggested 40-minute run of show

Use two browser profiles (guest or learner, and staff). Do not log everyone in and out of one window.

| Min | Who | Beat |
|---|---|---|
| 0–3 | Guest | `/` and a course card. “Published courses. No CMS.” |
| 3–7 | Guest | Phase 1 course page: who it is for, documents, fee, Apply |
| 7–12 | `learner@` | Dashboard, continue, mark the reading complete, inbox |
| 12–17 | Reviewer | `UAT-REVIEW-001`: checklist, decision. Optional live payment only if the applicant was prepared |
| 17–21 | Course Admin | Counts, locked eligibility and documents |
| 21–24 | Same user | Faculty home. Empty live-lesson list is expected |
| 24–30 | `learner@` or complete persona | Quiz **or** issued certificate and public verify — pick one if over time. Correct seeded answers are the first option |
| 30–35 | Facilitator laptop | Four captured letters. Inbox matches plain text |
| 35–38 | Finance | Payments visible; document URL denied |
| 38–40 | — | What production still changes: real payment, storage, mailbox. What does not change: admit-then-enrol, locked editions, reviewer versus finance |

Skip live Apply and live payment when the room is over 30 minutes already. The seeded reviewer application and the seeded active learner still show the product. Say they were prepared so the hour stays on the screens that matter.

---

## Open points (do not resolve by building ahead of a decision)

1. **Hostname and who operates the host** are not in the SRS. Confirm before provisioning.
2. **Customer inbox versus captured `.eml`.** Current `demo:prepare` refuses SMTP. A mailbox demo needs an explicit change and a security review (this host would send real mail).
3. **Clean applicant** is not seeded. Live Apply depends on a manual registration after every reset, unless a later seed adds an unused applicant on the Phase 1 course.
4. **Certificate letter** is not produced by the certificate seed. The demo must issue once before the session, or skip that letter.
5. **Apply-time eligibility** is not implemented. The eligibility screen is configuration and public copy only.
6. **Admission mail and enrolment mail** are still two messages. Do not merge them in the demo script until product says so.

---

## Out of scope for the implementation that may follow this plan

- Production rollout, Razorpay live mode, S3, SES, or a shared customer database
- New roles, new application or payment states, Q&A, forum, CMS, newsletter
- Revenue on Course Admin
- Invented CME credits, partner names, or faculty biographies
- Any change to enrolment, payment confirmation, or published-edition immutability
