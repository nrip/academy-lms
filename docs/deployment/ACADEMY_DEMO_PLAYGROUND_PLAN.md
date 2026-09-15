# Academy Demo Playground plan

**Status:** Plan only. Host model is approved. Operating decisions are [`../product/DEMO_MODE_DECISION.md`](../product/DEMO_MODE_DECISION.md) (`DEMO-MODE-1`). Where this plan still says a facilitator must grant Course Admin, or that mail stays `local_file` only, the decision note wins. Do not implement from either document until asked.  
**Date:** 2026-09-14  
**Audience:** Facilitator, and the engineer who will stand up the Hetzner host.  
**Goal:** One environment. A prospect can immediately explore a professionally populated academy, and can also create courses and work as an academy operator.

This is not a second product, and it is not a change to production architecture. It is one Hetzner Cloud Server running the existing Academy application, with seeded showcase data and self-service use of the same roles, catalogue, payments, and mail.

**Guided script (seeded personas and the 40-minute room):** [`RC1_DEMO_ENVIRONMENT_PLAN.md`](./RC1_DEMO_ENVIRONMENT_PLAN.md). That document is the showcase run-of-show. It is not a separate host.

**Do not use** the production path in [`HETZNER_VPS_DEPLOYMENT_GUIDE.md`](./HETZNER_VPS_DEPLOYMENT_GUIDE.md) (`APP_ENV=production`, no demo seeders, real customer data). Same stack. Different machine. Different `.env`.

---

## What “one playground” means

The application is a **single academy**. Branding, Razorpay, and mail are single-deployment. There is no tenant, no organisation workspace, and no second catalogue. Do not add one for this host.

Two ways in, one database:

| Path | Who | What they use |
|---|---|---|
| Guided demonstration | Facilitator, in the room | Seeded showcase courses and seeded personas |
| Self-service exploration | An external prospect, without a private academy | The public showcase, then their own courses if they have been granted Course Admin |

“Workspace” in this plan means **the courses already assigned to that user** (`course.view_assigned` and course-admin scope). It is not a new object. Creating a course already assigns that course, including future editions, to the creator. Other Course Admins do not see it unless they are also assigned. The public catalogue is different: every **published and locked** course is listed for everyone. Draft editions stay off the catalogue.

---

## 1. Demo playground user journey

A prospect should be able to do both of the following on the same URL. They are not two products.

### Immediate exploration (no account required)

1. Open the public storefront (`/`).
2. Browse published courses. The seeded showcase must be there: the Phase 1 learning course and the obesity catalogue course. See §2.
3. Open a course page: who it is for, required documents, fee, and Apply.
4. Sign in only when they want a learner or operator path.

This path works with today’s public catalogue. No new front door.

### Guided demonstration (facilitator)

Use the seeded personas and the 40-minute sequence in [`RC1_DEMO_ENVIRONMENT_PLAN.md`](./RC1_DEMO_ENVIRONMENT_PLAN.md): storefront, learner progress, reviewer queue, Course Admin counts, Faculty home, certificate, captured letters, finance denial.

Do not log the prospect into Super Admin. Do not print seeded passwords on the public site. Share them in the room only.

Before a formal guided session, confirm the public catalogue still shows the showcase and is not crowded by other people’s published courses (§3 and §9).

### Self-service as a learner

1. Register (`/register`). Registration creates an **Applicant** only.
2. Verify email. The account cannot proceed without that letter. See §8. This is the current blocker for an unattended external user.
3. Apply to a showcase course that they have not already applied to, upload a document, and submit.
4. A reviewer still has to decide. The seeded reviewer is a facilitator persona, not the prospect. Unattended admission is not a current workflow.
5. Payment on this host is the fake gateway (§7). Enrolment is created only when the application is Admitted.

`learner@uat.example.test` is already admitted on the Phase 1 course and cannot apply again. A new registrant can. That is the self-service learner, not the seeded one.

### Self-service as an academy operator

This is the path the prospect uses to create a course and see Course Admin and Faculty home for **their** course.

It is **not** available from Register today. Register does not grant `course.create`. There is no public “become an operator” action, and no admin screen that assigns roles. `RoleAssignmentService` exists and is audited; nothing in the HTTP app calls it except the local bootstrap script.

Until that grant is an approved workflow, operator exploration is **facilitator-assisted**:

1. The prospect registers and verifies (same mail constraint as the learner path).
2. A playground operator with a documented, audited grant assigns **Course Admin** and keeps **Applicant**, so they can both author and apply. Reason recorded. Not Reviewer, not Finance, not Super Admin.
3. The prospect signs in again (role assignment bumps `auth_version` and revokes sessions).
4. Course Admin is MFA-mandatory. They enrol TOTP before Course Admin or Faculty home opens. Do not disable MFA.
5. They create a course. The draft edition is theirs. The public site does not show it until it is published and locked.
6. They add chapters, lessons, eligibility, and required documents on the unlocked edition, then publish when they want it on the shared catalogue.
7. Faculty home lists that assigned course only. It does not show other prospects’ drafts, payments, or application numbers.

Do not describe this as their own academy. Branding, catalogue, payment, and mail stay the playground’s.

---

## 2. Seeded showcase academy

The showcase is the professionally populated academy. It is data, not a tenant. Prepare it with the existing command, on this host only:

```bash
php bin/jobs.php demo:prepare --confirm --migrate
```

`APP_ENV` must be `uat`. The command refuses `staging` and `production`. It also requires the fake payment gateway, local documents, the fake scanner, and `NOTIFICATION_EMAIL_ADAPTER=local_file` or `recording`.

| Showcase item | What the prospect sees | Stable identity |
|---|---|---|
| Learning course | Phase 1 Demo — Obesity Learning Pathway | `PHASE1-DEMO-CME-101`, `/courses/phase1-demo-obesity-learning` |
| Catalogue course | Certificate Course in Obesity and Metabolic Health | `WP02-DEMO-OBESITY-101` |
| Learner already studying | Progress, continue, quiz still open | `learner@uat.example.test` |
| Certificate example | Issued certificate and public verify. Does not send the certificate letter | `learner-phase1-complete@uat.example.test` |
| Reviewer queue | An application under review | `reviewer@uat.example.test`, `UAT-REVIEW-001` |
| Course Admin / Faculty | Counts and Faculty home for scoped showcase courses | `course-admin@uat.example.test` |
| Finance denial | Payments visible, documents denied | `finance@uat.example.test` |

Password: `UAT_SEED_PASSWORD` on this host, not the documented default, if the host is reachable on the internet. Seeded users have no MFA device until the facilitator enrols one.

Covers and live lessons are not in the seed. Upload one synthetic cover before a guided session, or say the card uses the placeholder. Faculty home will not show an upcoming live lesson unless one is authored.

Do not invent CME credits, partner hospitals, learner counts, or a featured ranking. The storefront lists every published course.

Protect the showcase by operating rule, not a new lock:

- Do not give external users scope on showcase courses.
- Do not edit a published showcase edition. Changes require the next edition.
- Do not use `uat:reset` during a self-service week without warning: it deletes `@uat.example.test` users and their showcase applications, then `demo:prepare` rebuilds them. It does **not** delete external users or courses they created.

---

## 3. Self-service demo workspaces

A self-service workspace is the Course Admin scope created with the course. `CreateCourseService` already inserts that assignment for the creator, including future editions, and writes an audit record. That is the isolation the product has. Use it. Do not add a workspace table.

What a prospect can and cannot see:

| Surface | Their own draft course | Someone else’s draft | Showcase, published | Their course after publish |
|---|---|---|---|---|
| Course Admin / Faculty | Yes, if they created it or were assigned | No | Only if assigned (seeded admin is; a prospect is not) | Yes |
| Public catalogue | No | No | Yes | Yes — visible to every visitor |
| Learner apply | Only after publish and an open batch | No | Yes | Yes, for any applicant |

Course code and slug are unique on the whole host. Two prospects cannot both create `CME-101`. A prospect must not reuse a showcase code.

Publishing is the shared-catalogue problem. RC1 has no private catalogue and no “demo only” flag. A published playground course appears beside the showcase. That is existing behaviour. Do not hide published courses to protect the demo; that would change the storefront rule.

Operating rule until product decides otherwise:

- Treat publish as “add this to the playground catalogue”, and say that on the way in.
- The guided script opens showcase courses by their known URLs if the catalogue also contains prospect courses.
- Do not publish a prospect course into a state that looks like the official showcase (same title as the seeded courses).

There is no self-service grant of Course Admin. §1 states the assisted grant. An unattended “create your academy” button would be a new workflow. It is out of this plan until product approves it (§13).

---

## 4. Roles and permissions

Use the roles that already exist. Do not add Faculty, Operator, or Demo Tenant.

| Role | Grant to an external prospect? | Why |
|---|---|---|
| Applicant | Yes, by Register | Learner path. Existing binder. |
| Course Admin | Only by an audited assignment after they have a verified account | Operator path. Needs `course.create`. MFA-mandatory. |
| Credential Reviewer | No | Segregation of duties. Showcase reviewer stays the seeded persona. |
| Finance Administrator | No | Must not see documents; must not be handed to the public. |
| Super Admin | No | Includes notification ops and must not be a prospect account. |

A prospect who should both study and author gets **Applicant and Course Admin**. Many-to-many roles already allow that. Do not invent a combined role.

Object scope still applies. Course Admin on their own course cannot open another prospect’s course, cannot approve credential documents, and cannot see a payment total on Course Admin. Faculty home is the same assignment, not a second login.

Reviewer and finance journeys in a guided demo stay on `reviewer@` and `finance@`. A prospect watches that; they do not receive those roles to try later from home.

---

## 5. Course creation limits

The product has **no per-user course cap**. Do not pretend one exists.

What already constrains creation:

- Permission `course.create` (Course Admin or Super Admin).
- Unique course code and unique slug, host-wide.
- An edition locks on publish, or when an application references it. Further changes are the next edition.
- Admin mutation rate limit: 60 per minute (`admin.mutation`).

A public host with no cap can fill the catalogue and the disk. A numeric playground cap is a **product decision that the SRS does not make**. Do not implement a number from this plan.

Until that decision, the operating stance is:

- Operator access is granted, not open on Register, which already limits how many people can create courses.
- The facilitator watches course count and disk before granting another operator.
- Proposed host policy, **not approved**: a small number of courses per operator (for example three), including drafts, so one visitor cannot squat codes or publish a long tail beside the showcase. Confirm or reject that number before any build. Rejecting it means the only limits are permission, uniqueness, and disk.

Do not cap the seeded showcase. Those courses are recreated by `demo:prepare`.

---

## 6. Storage quotas

There is **no per-user or per-course byte quota**. The real ceiling is disk on this server, plus the existing per-file caps.

| Kind | Existing cap | Notes for this host |
|---|---|---|
| Credential document | 10 MB | Platform cap. `demo:prepare` requires PHP `upload_max_filesize` at least 10M and `post_max_size` at least 16M. |
| Course cover | 5 MB default | `COURSE_COVER_MAX_BYTES`. Not seeded. |
| Lesson PDF / audio | 100 MB default | `LEARNING_MEDIA_PDF_MAX_BYTES`, `LEARNING_MEDIA_AUDIO_MAX_BYTES`. |
| Lesson video | 500 MB default | `LEARNING_MEDIA_VIDEO_MAX_BYTES`. No transcoding. |
| Profile image | 5 MB | Platform cap. |
| Support attachment | 10 MB, 5 files | Platform cap. |

Documents on this host use **local disk** and the **fake scanner**. Do not attach production S3 or Hetzner Object Storage and call it the academy document store. That would change the demo path `demo:prepare` requires, and it is not the production document design either.

PHP on this host must accept at least the credential cap. If operators are invited to upload lesson files, PHP limits must also cover the learning-media caps in `.env`. Leaving video at 500 MB on a public playground is a disk and abuse risk. Lowering `LEARNING_MEDIA_*_MAX_BYTES` on **this host only** uses existing configuration and does not change production. The playground numbers are not in the SRS. Proposed starting point, pending confirmation: PDF and audio 25 MB, video 50 MB, cover and credential caps unchanged. Production defaults stay as approved.

No aggregate quota will be built in the first playground. Watch disk. Stop granting operator access if free space is low. A per-user quota is a new control and needs a product decision (§13).

---

## 7. Fake payment handling

One payment adapter for the whole host. Showcase applications and prospect applications use the same fake gateway. Do not configure Razorpay live or test keys here.

Required:

```bash
PAYMENTS_FAKE_GATEWAY=1
```

Rules that do not change:

- The server calculates the amount. This host’s seed uses the stored fee (the obesity catalogue course is `15000.00` plus the edition’s GST). A new course starts at fee `0.00` until the operator sets it on an unlocked edition.
- The learner action is **Complete demo payment**.
- The next screen is **Confirming payment…**. That is not a receipt.
- Settlement is the webhook worker (`demo:process` or `demo:payment-capture`). The browser return never marks success.
- Enrolment is created only when the application is Admitted.
- A second run must not create a second enrolment.

Say this on the way in, in the facilitator notes. There is no product “demo payment” banner to turn on. Do not add one in this plan.

Checkout is already rate-limited (`payments.checkout`, 5 per 30 minutes). Keep it.

Finance on this host is the seeded persona, for the guided denial. Do not promise a prospect their own finance desk.

---

## 8. Email handling

Mail is the point where guided demo and unattended self-service do not fit the same adapter setting today.

| Need | What the product does now |
|---|---|
| Guided letters | `demo:prepare` requires `local_file` or `recording`. Letters are `.eml` files under `NOTIFICATION_LOCAL_MAIL_PATH`. |
| Register | Refuses if email cannot be sent. |
| External prospect | Cannot read `storage/mail`. A captured file does not finish their registration. |
| Production SMTP | Staging and production reject `local_file`. This host must stay `uat` so seed and fake payment remain legal. `demo:prepare` refuses SMTP. |

Do not point this host at the production academy mailbox or SES identity. Do not add multi-tenant mail routing.

**Supported now (assisted):** `NOTIFICATION_EMAIL_ADAPTER=local_file`. The facilitator opens the verification letter and the four branded letters from disk during a session. Subjects stay: “Verify your Academy account”, “Your application has been received”, “Congratulations! You have been admitted”, “Your certificate is ready”. Inbox still stores plain text after a successful send, and still does not copy the verification letter.

**Not supported now (unattended):** a prospect completing Register from their own inbox, or receiving those four letters at home, without a facilitator and without an approved mail change.

Options, none of which should be built until chosen:

1. Keep assisted mail. Self-service operator and learner completion happen on a call. No architecture change.
2. A playground mailbox page that shows that user’s captured letters. New UI. Not multi-tenant routing. Needs a security review so one user cannot read another’s token.
3. SMTP from this `uat` host to the prospect’s address. `demo:prepare` must be allowed to keep running, and the sending domain must not be the production academy. Needs an explicit exception to the prepare gate.

Until one of those is approved, do not advertise the host as a place an outsider can finish registration alone.

SMS stays unavailable. Do not configure a production SMS sender for this host.

---

## 9. Data lifecycle, archive, and reset

Two lifecycles share one database. Do not reset one by destroying the other.

### Showcase reset

```bash
php bin/jobs.php uat:reset --confirm
php bin/jobs.php demo:prepare --confirm
```

Allowed only when `APP_ENV` is `local`, `testing`, `ci`, or `uat`. This deletes users at `@uat.example.test` and applications marked `UAT-`, then rebuilds the showcase. It also drops facilitator MFA on those personas. It does **not** delete external accounts, their courses, their documents, or captured mail files.

Run it before a formal guided demo if the showcase was damaged. Warn anyone using the seeded learner that their session ends. Do not run it to “clean up” a prospect.

### Prospect data

No archive status, no per-user reset command, and no retention period exist. Do not invent a course status or a soft-delete for this plan.

What persists today:

- External users survive `uat:reset`.
- Courses they created survive, including published ones on the public catalogue.
- Local document files and `.eml` files survive until someone deletes them on disk.
- A locked edition they published cannot be edited. Unpublishing is not a documented demo control; do not hand-edit status columns.

Operating stance until product defines retention:

- Grant operator access only to people you can contact.
- Before a guided demo, list published courses. If a prospect course is public, open the showcase by URL and say the catalogue also contains visitor courses. Do not delete those rows in the session.
- Delete captured mail that contains tokens after a facilitated session (`storage/mail`), after the letters you still need for the next guided demo have been regenerated.
- Disk growth is the practical archive. If the volume fills, stop new grants. A retention job is a later decision (§13).

Do not copy this database to production. Do not restore production into this host.

---

## 10. Abuse prevention

This host will be abused if it is public and operator access is automatic. The controls below are the ones that already exist, plus operating rules that do not change production.

Already enforced:

- Registration: 10 per hour per IP, fail closed.
- Login and failed-login limits, forgot-password limits, verification resend limits.
- Document upload init: 20 per hour.
- Payment checkout: 5 per 30 minutes.
- Admin mutations: 60 per minute.
- Privileged roles require MFA. Course Admin is one of them.
- Finance cannot read documents. Reviewer cannot refund. Course Admin cannot approve credentials.
- Fake payment cannot capture real money.
- Seed and reset refuse production.

Operating rules for this host:

- Do not publish seeded passwords or persona emails on the site.
- Do not grant Reviewer, Finance, or Super Admin to an external user.
- Do not auto-grant Course Admin on Register.
- Keep `APP_DEBUG=false`.
- SSH and MySQL are not public. HTTPS only.
- No production secrets in `.env`.
- Watch disk and registration rate. A burst of Register is a reason to pause publicity, not to raise the limit.
- Inappropriate published courses are visible to every visitor. There is no catalogue moderation role. The grant process is the moderation. If a published course must come down, that is an operational incident, not a raw status update in a hurry — escalate, because published editions are immutable and there is no approved “hide from catalogue” action in this plan.

Not in the product, so not in this plan: captcha, a waiting room, or a per-user storage quota. Ask before adding any of them.

---

## 11. Demo onboarding flow

No new onboarding product. Use the screens that exist, and a short note the facilitator sends. Copy below is the note, not a new template in the app.

### What the prospect receives

1. The playground URL (HTTPS).
2. One sentence: this is a shared demonstration academy, not their production site, and payments are simulated.
3. Two choices:
   - **Look around:** open the site, browse courses, no login.
   - **Try it with us:** register, then join a short call so verification and Course Admin access can be completed. They will enrol an authenticator app before they can author.

### What the facilitator does on that call

1. Confirm the verification letter in `storage/mail` and let them finish verify. Do not read the token aloud if the button works.
2. If they only want the learner path, stop. They apply to a showcase course that is open. They will not be admitted unless a reviewer runs the existing queue.
3. If they want to author, assign Course Admin with a reason, have them sign in again, and stay while they enrol MFA. Store recovery codes in the session notes only.
4. They create one course. Show that it is absent from the public catalogue until publish, and that publish puts it on the shared catalogue.
5. Point them at Faculty home for that course.
6. Do not hand over reviewer or finance. Offer to show those on the seeded personas if they are still on the call.

### Guided room

Unchanged from the showcase script. Two browser profiles. Seeded logins. 30–45 minutes. Self-service creation is a different hour, not a beat crammed into the guided close.

---

## 12. Deployment architecture

One Hetzner Cloud Server. Ubuntu LTS. PHP 8.4, Nginx, MySQL 8.x on that server. Cron runs `php bin/jobs.php demo:process` during the period the host is in use, so scans and fake payment settlement do not wait for an SSH session.

| Choice | Value | Not this |
|---|---|---|
| Machine | A dedicated demonstration server | The customer production host, and not a second sandbox beside a demo box |
| `APP_ENV` | `uat` | `production` or `staging` (seed, reset, and fake payment refuse those) |
| Database | `academy_lms_demo` on this server | Customer data, and not a tenant schema |
| Branding | Single academy branding already in the app | Per-prospect logo or mail domain |
| Documents | `DOCUMENTS_STORAGE_DRIVER=local`, `DOCUMENTS_FAKE_SCANNER=1` | Production S3 |
| Payments | `PAYMENTS_FAKE_GATEWAY=1` | Razorpay |
| Mail | `local_file` until §8 is decided | Production SES |
| Debug | `APP_DEBUG=false` | Stack traces |
| Size | Prefer ≥ 2 vCPU / 4 GB RAM / 40 GB, more disk if lesson uploads are allowed | The smallest 2 GB plan if Dompdf and MySQL share the box |

`APP_URL` is the playground HTTPS origin. PHP upload limits meet §6.

Workers are the existing jobs, invoked by `demo:process` on this host because that command is legal in `uat` and stays idempotent. Do not invent a playground worker. Do not schedule `uat:reset`.

This does not change production architecture: one application, one database, one branding, one catalogue, role plus object scope, the same state machines, webhook payment confirmation, locked editions, and finance separated from documents.

Rollback of the host is the Hetzner snapshot plus the existing backup note in the trial guide. Rollback of application code is the same deploy path as any other environment. Do not migrate this schema by hand.

---

## 13. Open points (do not build past these)

1. **Unattended registration** needs a mail decision (§8). Assisted `local_file` is the only path that matches current gates.
2. **Course Admin grant** has no HTTP workflow. Assisted assignment is the only path that uses existing services. A self-serve operator button is a new workflow and needs product approval, audit, and MFA unchanged.
3. **Published prospect courses join the public catalogue.** Confirm that this is acceptable for the guided demo, or define an approved hide rule. Do not add a tenant flag to solve it.
4. **Course cap and per-user storage quota** are not in the SRS. Confirm or reject the proposed host numbers in §5 and §6 before any enforcement.
5. **Retention** for external users and their courses is undefined. `uat:reset` will not remove them.
6. **Hostname and who may grant Course Admin** are not specified. Name them before the host is public.
7. **Learning-media PHP limits** must be chosen with the disk budget. Production defaults of 100 MB / 500 MB are a poor fit for an open playground.

---

## Out of scope

- A second environment for sandbox versus guided demo
- Multi-tenancy, per-academy branding, or a private catalogue
- Changes to production deployment, Razorpay, S3, or SES
- New roles, new application or payment states, or enrolment before Admitted
- Q&A, forum, CMS, newsletter, or an email template editor
- Disabling MFA, weakening finance/document separation, or editing a published edition in place
