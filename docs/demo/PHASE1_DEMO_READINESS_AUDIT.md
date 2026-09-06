# Phase 1 Customer Demo — Readiness Audit

**HEAD audited:** `e387f0f` (`feat(lms): issue completion certificates with public verification`)  
**Branch:** `demo/mode-a-user-demo`  
**Date:** 2026-09-06  
**Scope:** Read-only audit of the implemented LMS Expansion Phase 1 customer journey. No application code or migrations changed for this document.

**Purpose:** Decide whether the product is ready to show a non-technical customer, and what must be fixed (or carefully facilitated) before that session — versus what blocks a later single-customer production deployment.

**Companions:** [`PHASE1_CUSTOMER_DEMO_ACCEPTANCE_CHECKLIST.md`](./PHASE1_CUSTOMER_DEMO_ACCEPTANCE_CHECKLIST.md), [`DEMO_SCRIPT.md`](./DEMO_SCRIPT.md), [`DEMO_READINESS_CHECKLIST.md`](./DEMO_READINESS_CHECKLIST.md), [`README.md`](./README.md), [`../product/PRODUCTION_READINESS_REGISTER.md`](../product/PRODUCTION_READINESS_REGISTER.md).

---

## Executive verdict

| Question | Answer |
|---|---|
| Can a skilled facilitator demonstrate Course Admin → publish → Mode A admit → player → MCQ → certificate → public verify on this HEAD? | **Yes — if they follow the live Course Admin path with a batch that starts immediately (or in the past), keep `demo:process` available, and ignore stale Mode A–only demo docs.** |
| Can a facilitator follow seeded Mode A + current `DEMO_SCRIPT.md` and reach learning/certificates without improvisation? | **No.** Seeded open batch tends to create **Scheduled** enrolment (no Continue learning). Seeded demo course has **no Phase 1 curriculum**. Script still says player/assessments/certificates/Course Admin are unimplemented. |
| Is the product ready for a single-customer production deployment? | **No.** Fake payments, local storage, local email, manual job processing, MFA gap for privileged roles, and missing S3/SES/Razorpay cutover remain open. |

**Bottom line:** Phase 1 **feature shells are largely complete**. Demo readiness is blocked primarily by **facilitation/data/doc gaps** and a few **UX dead-ends**. Production readiness is a separate, larger cutover.

---

## Journey map (what exists vs what breaks)

### 1. Course Admin

| Step | Status | Primary URLs | Notes |
|---|---|---|---|
| Create course | Ready | `/admin/courses` → `/admin/courses/new` → version overview | Course Admin persona seeded (`course-admin@uat.example.test`). |
| Curriculum | Ready (with traps) | `…/versions/{id}/curriculum` | Text + MCQ content types work. **PDF** type accepts `object_key` only; learner UI is a placeholder. |
| Question bank | Ready (discoverability gap) | `/admin/courses/{id}/question-bank` | Linked from course show; **not** from version/curriculum chrome. Easy to miss mid-demo. |
| Assessment config | Ready | `/admin/content-items/{id}/assessment` | Required for publish completeness on MCQ items. |
| Publish | Ready | `POST …/publish` | Fee must be &gt; 0; draft placeholders must be filled; incomplete MCQ blocks publish. |
| Create batch | Ready (timing trap) | `…/batches/new` | Batch `starts_at` controls Active vs Scheduled enrolment. Future start → learner cannot open content. |

**Stale UI copy (undermines confidence):** Admin course list still says *“Publish and batches arrive in a later work package.”* (`templates/pages/admin/courses/index.php`).

### 2. Learner

| Step | Status | Primary URLs | Notes |
|---|---|---|---|
| Register | Awkward | `/register` | Works, but **no Register link** on login; form is outside the main app shell. Demo usually uses seeded learner. |
| Catalogue / apply | Ready | `/courses`, `/courses/{slug}`, `/courses/{slug}/batches` | Seeded obesity course still has FAQ claiming player/certs out of scope. |
| Mode A documents / submit | Ready with ops dependency | `/applications/{id}` | Requires **`composer demo:process`** for scan/queue. |
| Reviewer | Ready | `/reviewer/applications` | Facilitator must claim the **live** application, not a canned scenario id. |
| Payment | Ready with ops dependency | application payment → “Confirming…” | Fake gateway + second **`demo:process`**. Browser never trusts success (correct). |
| Dashboard enrolment | Conditional | `/dashboard` | **Continue learning** only when lifecycle = `active`. Scheduled shows “Opens when batch starts”. |
| Player / lessons | Ready on live curriculum | `/learning/enrolments/{id}` | No top-nav “My learning”; entry is dashboard CTA / deep link only. |
| Assessment | Ready | start → `/learning/attempts/{id}` | After pass: **Back to outline** only — no certificate CTA. |
| Certificate | Ready (easy to miss) | `…/certificates` → `/certificates/{id}` (+ `/pdf`) | Linked from outline. Issue is silent; list may already contain cert after last mandatory complete/pass. |

### 3. Public

| Step | Status | Primary URLs | Notes |
|---|---|---|---|
| Course discovery | Ready | `/courses`, `/courses/{slug}` | Guest nav has Courses. |
| Certificate verification | Ready (no discovery) | `/verify/certificates/{number}` | Works logged out; limited fields; **no public nav entry** — only link from certificate page. |

---

## 1. Critical blockers (demo day)

These will stop or embarrass a customer session if not handled.

| ID | Finding | Why it hurts | Evidence / where |
|---|---|---|---|
| C1 | **Stale Mode A demo script & readiness checklist** still claim player, assessments, certificates, and Course Admin builder are unimplemented | Facilitator/customer told the wrong story; Phase 1 work invisible | `DEMO_SCRIPT.md` “Features not yet implemented”; `DEMO_READINESS_CHECKLIST.md` out-of-scope line |
| C2 | **Seeded open batch starts ~45 days out** → Admit creates **Scheduled** enrolment → no Continue learning | Learner path appears broken after a successful Mode A demo | Batch seed timing; `EnrolmentFactory` Active vs Scheduled; dashboard CTA |
| C3 | **No seeded Phase 1 curriculum** on the demo catalogue course (WP-L9 gap) | Even an Active enrolment on the obesity course shows empty outline | `demo:prepare` / WP02 catalogue seeder; checklist still expects seed *or* live path |
| C4 | **Manual `demo:process` is a hard dependency** after document upload/submit and after demo payment | Non-technical customer / single-terminal facilitator freezes on “Confirming…” or unscanned docs | `README.md`, payment result tip, `DemoProcessService` |
| C5 | **Live Course Admin courses lack eligibility / required-document authoring UI** | Publish + open batch with **zero** document requirements weakens Mode A review demo on a newly built course | Create-course path; doc requirements remain seed/catalogue-era |

**Safe demo path (until C1–C5 fixed):**

1. Update facilitator script to Phase 1 beats (or temporarily print the acceptance checklist script).  
2. As Course Admin: build curriculum + bank + assessment; set fee; publish.  
3. Create batch with **`starts_at` ≤ now**.  
4. Learner applies to **that** course/batch (not only the seeded future cohort).  
5. Keep a second terminal ready for `composer demo-process` after docs and after pay.  
6. Complete all mandatory lessons + pass MCQ → Certificates from outline → public verify link.

---

## 2. High priority fixes (pre-customer demo)

Not always hard blockers, but high likelihood of stuck/confusion.

| ID | Finding | Type |
|---|---|---|
| H1 | After assessment **pass**, no CTA to certificates / outline progress celebration | Missing navigation / CTA |
| H2 | After certificate **issue**, no dashboard flash, no “View certificate” on enrolment card | Missing navigation / empty feedback |
| H3 | Learner has **no “My learning”** (or Certificates) item in primary nav | Missing navigation |
| H4 | Question bank only from course show — missing from version/curriculum chrome | Missing navigation |
| H5 | Admin list copy still says publish/batches are future | Confusing UX / stale copy |
| H6 | Catalogue FAQ on seeded course still says player/certs out of scope | Confusing UX / stale copy |
| H7 | Choosing **PDF** content type in curriculum looks real; learner gets placeholder “media infrastructure” | Incomplete journey / trap |
| H8 | Register: no link from login; bare shell | Missing navigation / confusing UX |
| H9 | Public verify has no guest discovery path (must know number or open private link) | Missing navigation (acceptable if framed) |
| H10 | Phase 1 acceptance checklist **Step 4** pass boxes and **DoD gate** still unchecked | Process / proof gap (run smoke same day) |
| H11 | `PRODUCTION_READINESS_REGISTER` still marks player/assess/cert as “future” while Phase 1 code exists | Doc drift (confuses stakeholders) |
| H12 | New Course Admin path: no UI for eligibility rules / document requirements | Incomplete for Mode A on live-built courses (ties to C5) |

---

## 3. Nice-to-have improvements

| ID | Finding |
|---|---|
| N1 | Certificate branding is functional but minimal (simple HTML + basic PDF text) — fine for demo, not “pretty” |
| N2 | Autosave UX on attempts is form POST (no inline “Saved…” AJAX) — works, slightly clunky |
| N3 | Align `/register` with Bootstrap app shell |
| N4 | Version page deep links to public catalogue / apply after batch create |
| N5 | Empty states: richer guidance when outline has zero content (“Ask Course Admin to publish curriculum”) |
| N6 | Explicit message when certificate deferred because learner name missing |
| N7 | Guest footer link “Verify a certificate” |
| N8 | Facilitator one-pager: env flags, persona table including Course Admin, `demo-process` moments |

---

## 4. Missing screens / empty states / error handling

### Missing or thin screens (demo-relevant)

- Course Admin: **eligibility rules** and **document requirements** editors for a new CourseVersion.  
- Learner: dedicated **My learning** hub (dashboard enrolment table is the only entry).  
- Public: certificate verify **landing/search** (optional).  
- PDF/video **player** surfaces (explicitly deferred; PDF half-wired is the danger).

### Empty states (present vs weak)

| Surface | Assessment |
|---|---|
| Admin course list | Has empty + New course CTA |
| Curriculum modules/items | Has empty copy |
| Learning outline with no content | Weak — incomplete curriculum looks like a bug |
| Certificates list before eligibility | Message + incomplete titles — good |
| Catalogue empty | Present |
| Register pending / verify email | Depends on local mail adapter — easy to strand without facilitator |

### Error handling

| Area | Assessment |
|---|---|
| Admin publish / locked version | Generally clear 409 / conflict messaging |
| Assessment in-progress resume | Handled (redirect to existing attempt) |
| Payment confirming | Clear; depends on workers |
| Scheduled enrolment opening content | Conflict/message on player — but dashboard CTA already hidden |
| Certificate ownership | 403 — good |
| Public verify unknown number | 404 page with message — good |

---

## 5. Where a non-technical customer gets stuck

1. **Finishes Mode A on the seeded March 2027 cohort** → sees enrolment but only “Opens when batch starts”.  
2. **Opens Active enrolment on seeded obesity course** → empty outline (no lessons).  
3. **Waits on Confirming payment…** with no one running `demo:process`.  
4. **Passes MCQ** and looks for a certificate button that isn’t there.  
5. **Course Admin** cannot find Question bank after living on curriculum/version pages.  
6. **Adds a PDF lesson** expecting download; learner sees placeholder.  
7. **Tries self-registration** from login page — no Register affordance.  
8. **Reads DEMO_SCRIPT** “not yet implemented” list and concludes Phase 1 learning was never built.

---

## 6. Production / single-customer deployment blockers

These are **not** required to pass a local customer demo with fake adapters, but they block real deployment.

### Payment gateway

| Item | Status |
|---|---|
| Demo | `PAYMENTS_FAKE_GATEWAY=1` required for prepare |
| Production | Real Razorpay order + webhook signature + worker path (`PR-RZP`) |
| Trust model | Correct: browser return is informational; webhook/worker confirm |
| Gap | Credential injection, webhook URL, no reliance on `demo:process` / fake capture UI |

### Email / notifications

| Item | Status |
|---|---|
| Demo | `NOTIFICATION_EMAIL_ADAPTER=local_file` (or recording) |
| Production | SES (or approved provider) — `PR-EMAIL` |
| Gap | Registration, email verify, password reset, transactional mail fail closed without provider |
| Ops | `/admin/notifications` exists for inspection/retry in demo |

### File storage

| Item | Status |
|---|---|
| Demo | `DOCUMENTS_STORAGE_DRIVER=local` + fake scanner |
| Production | Private S3 + IAM + signed URLs — `PR-S3`; malware scanning — `PR-MALWARE` |
| Gap | No production S3 adapter wired in-repo for cutover |

### Content upload gaps

| Item | Status |
|---|---|
| Credential documents | Upload path exists (Mode A) with size/type validation |
| Learning PDF | Type exists; **no learner file delivery**; admin object_key only |
| Video | Not in Phase 1 content types / player |
| Images / rich media in lessons | Text body only |

### Workers / process supervision

| Item | Status |
|---|---|
| Demo | Manual `composer demo-process` |
| Production | Cron/supervisor for scan, outbox, webhooks, notifications — `PR-CRON` |
| Gap | Examples in ops docs; not a hosted scheduler |

### Auth / MFA

| Item | Status |
|---|---|
| Policy | Privileged roles (incl. Course Admin) expect MFA (AGENTS / UAT notes) |
| Reality | Login binds fully authenticated; **no MFA enrol/challenge UI** in router |
| Gap | Compliance blocker for production privileged access |

### Other production register items (still open)

Alerts (`PR-ALERT`), backup/restore (`PR-BACKUP`), SMS (`PR-SMS`), refunds (`PR-REFUND`), cancel flows (`PR-CANCEL`), in-app notification centre (`PR-INAPP`), etc. — see readiness register.

---

## 7. Recommended order of work

Prioritised for **customer demo first**, then **pilot deployment**.

### Wave A — Unblock the customer demo (docs + data + copy; minimal risk)

1. Rewrite / replace facilitator materials: Phase 1 beats in `DEMO_SCRIPT.md` + update `DEMO_READINESS_CHECKLIST.md` out-of-scope list.  
2. Fix or document batch timing: seeded open batch `starts_at` in the past **or** facilitator note “create batch starting today”.  
3. WP-L9-style seed: published version with modules + text + MCQ + linked questions **or** a printed “live build only” runbook.  
4. Stale copy: admin courses index; seeded catalogue FAQ.  
5. Same-day smoke: tick Phase 1 checklist Step 4 + DoD gate on the laptop that will present.

### Wave B — UX so the customer doesn’t get lost (small UI)

6. Post-pass assessment CTA → certificates (or outline + cert link).  
7. Dashboard: certificate / Continue learning when cert exists; Scheduled explanation if still used.  
8. Learner nav: My learning (and optional Certificates).  
9. Course Admin: Question bank + assessment links on version/curriculum chrome.  
10. Hide or clearly label PDF content type until media works (“coming soon”).  
11. Login: Register link (if self-serve is in the script).

### Wave C — Mode A completeness on live-built courses

12. Minimal Course Admin UI (or seed helper) for **document requirements** (+ eligibility if shown on catalogue).  
13. Ensure publish completeness messages list missing requirements in plain language.

### Wave D — Single-customer production cutover (after demo)

14. Real Razorpay + webhook + remove fake capture from the story (`PR-RZP`).  
15. Private S3 + signed URL pack (`PR-S3`); scanner decision (`PR-MALWARE`).  
16. SES (or approved email) + fail-closed config (`PR-EMAIL`).  
17. Supervised workers/cron (`PR-CRON`); stop relying on `demo:process`.  
18. MFA for privileged roles.  
19. Learning PDF (and later video) media path if customer content requires it.  
20. Refresh `PRODUCTION_READINESS_REGISTER` for Phase 1 learning (player/assess/cert no longer “future” for this expansion).

---

## 8. Demo-day facilitator cheat sheet (until Wave A lands)

| Do | Don’t |
|---|---|
| Use Course Admin to build the demo course live (or a pre-built **current** batch) | Rely on `DEMO_SCRIPT` “not implemented” list |
| Set batch start **today or earlier** | Use only the future seeded open cohort for learning |
| Run `demo:process` after docs submit and after demo pay | Leave the browser on Confirming… |
| Complete all mandatory items then open **Certificates** from outline | Expect a certificate button on the attempt result screen |
| Show public verify via the certificate page link | Promise production SES/S3/Razorpay in this build |
| Mention PDF/video as out of demo if asked | Author PDF lessons as if downloads work |

**Personas (local):** Course Admin, Learner, Reviewer, Finance (SoD optional), Ops notifications — password from `demo:prepare` / `Uat-Demo-Passw0rd!`.

**Env (local demo):** `PAYMENTS_FAKE_GATEWAY=1`, `DOCUMENTS_STORAGE_DRIVER=local`, `DOCUMENTS_FAKE_SCANNER=1`, `NOTIFICATION_EMAIL_ADAPTER=local_file`, `composer demo-serve`.

---

## 9. Audit sign-off

| Gate | Status at `e387f0f` |
|---|---|
| Phase 1 feature implementation (WP-L1…L8) present in app | Met |
| Facilitator-safe end-to-end without improvisation | **Not met** (C1–C4) |
| Non-technical customer unattended journey | **Not met** |
| Single-customer production deployment | **Not met** (Wave D) |

**Recommended call:** Proceed to customer demo only after **Wave A** (and ideally **H1–H5** from Wave B). Treat Wave D as a separate production readiness track.

---

*Audit only — no migrations or application code were modified to produce this document.*
