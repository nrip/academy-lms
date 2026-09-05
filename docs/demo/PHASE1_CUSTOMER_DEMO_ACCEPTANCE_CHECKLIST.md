# Phase 1 Customer Demo — Acceptance Checklist

**Purpose:** Exact browser journey that must work for the customer demo within 48 hours.  
**Authority:** LMS Expansion Phase 1 demo-first implementation plan (WP-L1…WP-L9).  
**Base URL:** `http://127.0.0.1:8080`  
**Prepare (target):** `composer demo-prepare` then `composer demo-serve`  
**Jobs (as today):** `composer demo-process` after document upload/submit and after demo payment  

**Pass rule:** Every step below can be completed in a browser with the stated role, URL, screen, and action. Facilitator scripts and seed shortcuts (WP-L9) may pre-stage data, but the **live path** for steps 1–3 and 5–7 must still be demonstrable.

**Out of demo (do not block pass):** CourseVersion under-review workflow, video content, passing models B/C, Faculty role, certificate revoke UI, production SES/S3/Razorpay.

---

## Personas

| Role | Email (target seed) | Password |
|---|---|---|
| Course Admin | `course-admin@uat.example.test` | `UAT_SEED_PASSWORD` or `Uat-Demo-Passw0rd!` |
| Learner | `learner@uat.example.test` | same |
| Reviewer | `reviewer@uat.example.test` | same (Mode A only) |
| Finance | `finance@uat.example.test` | optional SoD check only |

Login always starts at: **`/login`**

---

## Journey map (seven demo beats)

### 1. Course Admin creates course

| Field | Value |
|---|---|
| **URL** | `GET /login` → after login `GET /admin/courses` → `GET /admin/courses/new` → `POST` create → `GET /admin/courses/{courseId}/versions/{versionId}` |
| **User role** | Course Admin (`course_admin`) |
| **Expected screen** | Course list; then “New course” form (code, slug, master title); then Draft Course Version overview (title, description, fee/GST fields already on `course_versions`) |
| **Expected action** | Create Course identity + Draft Version 1. Land on Draft version builder home. Nav shows Course Admin courses (not Reviewer document tools). |

**Pass criteria**

- [x] Course Admin can sign in and open `/admin/courses` *(WP-L1)*
- [x] Reviewer / Finance / Learner receive **403** (or no nav link) on `/admin/courses` *(WP-L1 HTTP tests)*
- [x] New course appears in admin list with a **Draft** version *(WP-L1)*
- [x] Draft is **unlocked** (`locked_at` null — verified by ability to edit later steps) *(WP-L1)*

> Remaining checklist steps 3–7 unlock in WP-L2 / WP-L6…WP-L8.

---

### 2. Course Admin creates modules / content

| Field | Value |
|---|---|
| **URL** | `GET /admin/courses/{courseId}/versions/{versionId}/curriculum` (modules + content) · question bank: `GET /admin/courses/{courseId}/question-bank` · assessment on item: `GET /admin/content-items/{contentId}/assessment` |
| **User role** | Course Admin |
| **Expected screen** | Curriculum builder: ordered modules; within a module, content items (`text_lesson` and one `mcq_assessment`). Question bank with MCQ stem + options. Assessment config (pass %, max attempts, linked questions). |
| **Expected action** | Create **≥2 modules**, **≥2 text lessons**, **1 MCQ assessment** content item with **≥5 bank questions** linked and pass threshold set (e.g. 60%). Save successfully on Draft only. |

**Pass criteria**

- [x] Modules and content visible in curriculum outline *(WP-L3 — text lessons + mcq_assessment content type via WP-L5)*
- [x] Text lesson body editable and saved *(WP-L3)*
- [x] MCQ questions saved in course question bank; correct answers shown only on Course Admin bank UI *(WP-L4)*. Learner-facing assessment URLs still deferred to WP-L7.
- [x] MCQ assessment content can be configured with title, question count, pass %, max attempts, and linked bank questions *(WP-L5)*
- [x] Attempting the same edits on a **published/locked** version is blocked (**409** / clear message to clone Version N+1) *(WP-L3 curriculum + WP-L5 assessment)*

> Remaining checklist steps 3–7 unlock in WP-L2 / WP-L6…WP-L8.

---

### 3. Course Admin publishes course

| Field | Value |
|---|---|
| **URL** | `GET /admin/courses/{courseId}/versions/{versionId}` → `POST .../publish` → `GET /admin/courses/{courseId}/versions/{versionId}/batches/new` → create batch → confirm `GET /courses/{slug}` (public catalogue) |
| **User role** | Course Admin (publish + batch); catalogue may be viewed as Guest/Learner |
| **Expected screen** | Version detail with **Publish** action; success shows status **Published** and immutable notice. Batch form (name, dates, capacity, applications window). Public course detail shows the published version and open batch. |
| **Expected action** | Publish Draft → version locks. Create one batch **open for applications**. Confirm course appears in public catalogue with Apply available. |

**Pass criteria**

- [ ] After publish, curriculum/fee fields cannot be edited (409 / UI disabled + server enforce)
- [ ] Public `GET /courses` lists the course; `GET /courses/{slug}` shows fee, eligibility (cloned/seeded), open batch
- [ ] `GET /courses/{slug}/batches` shows the new batch as selectable

**Facilitator note:** If live publish is slow, WP-L9 may seed an already-published curriculum version + open batch; facilitator must still show **either** live publish **or** that a Course Admin–built published version is what learners apply to.

---

### 4. Learner enrols

Mode A admissions path is **unchanged**. Enrolment means Application **Admitted** + Enrolment row (not “self-enrol”).

| Field | Value |
|---|---|
| **URL** | `GET /login` → `GET /courses/{slug}` → `GET /courses/{slug}/batches` → `POST /applications` → `GET /applications/{id}` (workspace: profile, documents, submit) → reviewer path → `GET /applications/{id}/payments/...` (Pay / Complete demo payment) → `GET /dashboard` |
| **User role** | Learner → Reviewer → Learner (jobs: `demo:process`) |
| **Expected screen** | Course/batch apply; Application workspace; after submit + scan: Under review. Reviewer queue `/reviewer/applications` → claim → approve → Payment required. Learner Pay → **Confirming payment…** → after `demo:process`: Admitted + Enrolment on **My Applications** / dashboard. |
| **Expected action** | Complete Mode A against the **Phase 1 published batch**. End state: Active (or Scheduled→Active) Enrolment for that course version. |

**Pass criteria**

- [ ] Application binds to the published `course_version_id` / new batch
- [ ] Browser payment return never shows final “Successful” as source of truth
- [ ] After workers: Application **Admitted**, Enrolment exists, dashboard shows enrolment card
- [ ] Finance still cannot open credential document URLs

**Sub-steps (existing URLs)**

| Step | URL | Role | Action |
|---|---|---|---|
| 4a Apply | `/courses/{slug}/batches` → create application | Learner | Select open batch → Apply |
| 4b Documents + submit | `/applications/{id}` | Learner | Upload required docs → Submit; run `demo:process` |
| 4c Review | `/reviewer/applications` → application detail | Reviewer | Claim → approve docs → approve to payment pending |
| 4d Pay | Application payment / checkout UI | Learner | Complete demo payment → `demo:process` |
| 4e Confirm | `/dashboard` | Learner | See Admitted + Enrolment; **Continue learning** available when Active |

---

### 5. Learner accesses course player

| Field | Value |
|---|---|
| **URL** | `GET /dashboard` → `GET /learning/enrolments/{enrolmentId}` (outline) → `GET /learning/enrolments/{enrolmentId}/items/{contentId}` |
| **User role** | Learner (owner of enrolment only) |
| **Expected screen** | Learning outline: modules and content with completion state. Text lesson screen with body + **Mark complete**. Locked sequential items clearly unavailable until prior mandatory items done. |
| **Expected action** | Open enrolment player; open a text lesson; mark complete; progress updates on outline. |

**Pass criteria**

- [ ] Only **Active** enrolment can open player (Scheduled without activation: no content, or clear message)
- [ ] Learner cannot open another user’s `/learning/enrolments/{id}` (403)
- [ ] Content is from enrolment’s `course_version_id` only
- [ ] Dashboard links into player for the demo enrolment

---

### 6. Learner completes assessment

| Field | Value |
|---|---|
| **URL** | From outline, open MCQ item → `POST /learning/enrolments/{enrolmentId}/assessments/{assessmentId}/attempts` (start) → attempt UI `GET /learning/attempts/{attemptId}` → autosave responses → `POST /learning/attempts/{attemptId}/submit` → result screen |
| **User role** | Learner |
| **Expected screen** | Attempt in progress (questions/options, no correct flags). After submit: score, pass/fail vs threshold. Outline shows assessment content completed when passed. |
| **Expected action** | Start attempt; answer enough questions to **pass**; submit; see pass result. |

**Pass criteria**

- [ ] Second concurrent in-progress attempt blocked (409)
- [ ] Scoring matches snapshot (editing bank afterward does not change submitted score)
- [ ] Failed attempt can retry up to `max_attempts` / cooldown rules configured in admin
- [ ] On pass, assessment ContentItem progress = completed

---

### 7. Learner receives certificate

| Field | Value |
|---|---|
| **URL** | After all mandatory items + passed MCQ: `GET /dashboard` and/or `GET /learning/enrolments/{enrolmentId}/certificates` → open certificate `GET /certificates/{certificateId}` · public `GET /verify/certificates/{certificateNumber}` (logged out) |
| **User role** | Learner (own cert); Guest for public verify |
| **Expected screen** | Completion certificate (academy branding, learner certificate name, course title, issue date, certificate number). Public verify: valid/revoked, learner name, course, type, issue date — **no email, phone, or address**. |
| **Expected action** | Show certificate issued after completion; open public verify URL and confirm limited fields. |

**Pass criteria**

- [ ] Certificate does **not** issue before mandatory content + MCQ pass
- [ ] Re-triggering issuance does not create a second current certificate
- [ ] Public verify works without login and without contact PII
- [ ] Enrolment was still created only via Mode A Admit (no certificate-created enrolment)

---

## End-to-end facilitator script (time box ~25–35 min)

| Time | Beat | Primary URL |
|---|---|---|
| 0–2 | Frame: real app; Mode A unchanged; learning after Admit | `/login` |
| 2–8 | Course Admin creates course + curriculum + MCQ | `/admin/courses` … |
| 8–10 | Publish + open batch; show public catalogue | `/admin/.../publish` → `/courses/{slug}` |
| 10–22 | Learner Mode A enrol (apply → review → pay → workers) | `/courses/...` → `/dashboard` |
| 22–28 | Player + mark lessons complete | `/learning/enrolments/{id}` |
| 28–32 | Pass MCQ | `/learning/attempts/{id}` |
| 32–35 | Certificate + public verify | `/certificates/...` → `/verify/certificates/...` |

Optional 1-minute SoD: Finance login cannot open document downloads.

---

## Demo readiness gate (48-hour DoD)

All must be checked before calling the customer demo ready:

- [ ] Steps **1–7** pass criteria above
- [ ] `composer demo-prepare` creates Course Admin persona + usable curriculum path (live or seeded published version)
- [ ] Existing Mode A personas (`learner`, `reviewer`, `finance`) still work
- [ ] Application / Payment / Document / Enrolment state machines **unchanged**
- [ ] Published CourseVersion remains immutable
- [ ] Known local PHPUnit issues documented; critical path smoke (Mode A E2E + new learning smoke) run the day of demo

---

## URL index (Phase 1 targets)

| Area | Method | Path |
|---|---|---|
| Login | GET | `/login` |
| Admin courses | GET | `/admin/courses` |
| New course | GET/POST | `/admin/courses/new` |
| Draft version | GET | `/admin/courses/{courseId}/versions/{versionId}` |
| Curriculum | GET | `/admin/courses/{courseId}/versions/{versionId}/curriculum` |
| Question bank | GET | `/admin/courses/{courseId}/question-bank` |
| Assessment config | GET | `/admin/content-items/{contentId}/assessment` |
| Publish | POST | `/admin/courses/{courseId}/versions/{versionId}/publish` |
| New batch | GET/POST | `/admin/courses/{courseId}/versions/{versionId}/batches/new` |
| Catalogue | GET | `/courses`, `/courses/{slug}`, `/courses/{slug}/batches` |
| Application | GET/POST | `/applications`, `/applications/{id}` |
| Reviewer | GET | `/reviewer/applications` |
| Dashboard | GET | `/dashboard` |
| Player outline | GET | `/learning/enrolments/{enrolmentId}` |
| Player item | GET | `/learning/enrolments/{enrolmentId}/items/{contentId}` |
| Attempt | GET | `/learning/attempts/{attemptId}` |
| Own certificates | GET | `/learning/enrolments/{enrolmentId}/certificates` |
| Public verify | GET | `/verify/certificates/{certificateNumber}` |

Paths under `/admin/*`, `/learning/*`, `/certificates/*`, and `/verify/certificates/*` are **Phase 1 deliverables** (WP-L1 onward). Catalogue, applications, reviewer, payment, and dashboard URLs are **existing Mode A**.

---

*Checklist version: Phase 1 customer demo / pre-WP-L1*  
*Do not treat this document as permission to expand scope beyond the approved work packages.*
