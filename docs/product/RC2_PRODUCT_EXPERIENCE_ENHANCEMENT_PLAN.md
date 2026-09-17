# RC2 Product Experience Enhancement Plan

**Status:** Waves A–D implemented on the product-experience track (2026-09-17). Decisions: `PX-ONBOARD-1`, `PX-TERM-1`, `PX-BRAND-1`, `PX-PREVIEW-1`.  
**Date:** 2026-09-17  
**Baseline:** After RC1, RC2 Slice 1 (Learning Q&A), and Wave A (onboarding, dashboard IA, terminology).  
**Authority:** SRS v6.1 wins on conflict. Decision Log wins on product choices not covered by the SRS. Do not invent state-machine changes, multi-tenancy, or features listed under Explicit exclusions.

**Companions:** [`PRODUCT_EXPERIENCE_GAP_ASSESSMENT.md`](./PRODUCT_EXPERIENCE_GAP_ASSESSMENT.md) (pre-RC1; partially superseded), [`RC1_IMPLEMENTATION_PLAN.md`](./RC1_IMPLEMENTATION_PLAN.md), [`LEARNING_QA_DESIGN.md`](./LEARNING_QA_DESIGN.md), [`LEARNING_EXPERIENCE_ROADMAP.md`](./LEARNING_EXPERIENCE_ROADMAP.md), [`DECISION_LOG.md`](./DECISION_LOG.md).

---

## 0. Purpose and posture

RC1 and RC2 Slice 1 completed the functional LMS spine: public academy face, admission → payment → enrolment → player → certificate, plus private lesson Q&A. The product is **capable**. The next phase is to make it feel like a **premium professional academy** — clearer onboarding, stronger progress narrative, coherent identity, confident course creation, useful operations insight, and a few learner delight features.

This document assesses six enhancement slices. It does not authorise coding. Each slice that needs a new durable concept (goals, bookmarks, notes, branding admin UI, revenue on Course Admin) must land a Decision Log entry before implementation.

### Stored model (unchanged)

```text
Course → CourseVersion → Module → ContentItem
```

Customer language remains: **Course · Chapter · Lesson · Quiz · Edition · Batch/Cohort**. Do not add a Lesson table. Do not weaken CourseVersion immutability, Application/Enrolment/Payment machines, or segregation of duties.

### Explicit exclusions (do not plan or build)

| Excluded | Why |
|---|---|
| AI assistant / tutor / course generation | Future consideration; not in SRS |
| Chat / websockets / typing indicators | Out of RC2 Q&A design (`LX-QA-1`) |
| Forum / community / peer discussion | Separate from LMS; roadmap §5 only |
| Mobile apps | Out of stack and scope |
| Marketplace / multi-academy catalogue | Conflicts with single-deployment academy identity |
| Multi-tenancy | Forbidden without product redesign (`DEMO-MODE-1`, branding docs) |
| Annotations / collaborative editing / social reactions | Excluded by this brief |

---

## 1. Executive maturity (post RC1 / RC2 Slice 1)

| Layer | Maturity | Notes |
|---|---|---|
| Public academy | Usable | `GET /` storefront, catalogue cards with covers, course detail. Still generic copy; identity is env-driven only. |
| Registration / login | Functional | Minimal fields (email, mobile, password, terms). Verify email/mobile. Profile is separate and often empty for new accounts. |
| Learner dashboard | Good spine | “My learning”, progress bars, continue learning, live sessions, certificates, updates inbox. Still partly list/table oriented. |
| Learner player | Strong | Chapter/lesson outline, media types, mark complete, quiz, live, **Ask a question**. Feels most “product-like”. |
| Course admin | Operational | Counts, covers, eligibility, curriculum, publish/clone/batch. Still multi-page CMS, not a creation wizard. |
| Faculty | Usable | `/faculty` courses, live sessions, Q&A queue. Not a teaching console with readiness or engagement. |
| Certificates | Functional | HTML + PDF + public verify. Branding via env issuer/colour. Presentation is basic. |
| Email / notifications | Functional | Branded HTML layout; learner inbox after successful send. Some subjects still hardcode “Academy”. |
| Q&A | Delivered (RC2 S1) | Private lesson threads; faculty responses; email + learner inbox on respond. No attachments/replies/forum. |
| Ops analytics | Thin | Scoped counts on Course Admin / Faculty. No engagement or completion analytics. Reporting domain empty. |
| Bookmarks / notes / goals | Absent | No models or UI. |

---

## 2. Cross-cutting inventory of decisions

| ID (proposed) | Topic | Why it blocks code |
|---|---|---|
| `PX-ONBOARD-1` | Soft post-verify profile steps vs optional later | Registration must stay unblocked; decide which fields are suggested vs required for apply |
| `PX-BRAND-1` | Env-only branding vs Super Admin settings screen | Still one academy per deployment; decide who may change logo/colour without redeploy |
| `PX-TERM-1` | “Edition” and “Batch” vs “Cohort” in all admin UI | Customer language consistency; stored `CourseVersion` / `Batch` names stay |
| `PX-ANALYTICS-1` | Revenue/payment summary on Course Admin | SRS puts revenue on Finance (`REQ-DASH-FIN-1`); aggregate-only may be allowed with sign-off |
| `PX-NOTES-1` | Private lesson notes / bookmarks | New tables; privacy (Finance must not see notes); not collaborative |
| `PX-GOALS-1` | Learner-owned goals vs system progress only | New learner-owned data; must not replace `content_progress` |

---

## Slice 1 — User Registration & Onboarding Experience

### 1. Current state

- Register: email, mobile, password, terms + privacy (`templates/pages/register/form.php`).
- Pending: check email; parallel mobile verify path.
- Login / forgot / reset exist; branded layout via `AcademyBranding`.
- Profile (personal / professional / qualifications) is a separate surface after login; not part of register.
- Staff demo personas now get profile stubs; learners get stubs at registration.

### 2. User experience gaps

- Form looks like a utility card, not a professional academy welcome.
- Password field has no strength/guidance UX (server rules exist; UI does not teach them).
- Validation messages are correct but not conversational.
- Verification pages are intentionally minimal; after verify, landing can feel abrupt.
- New learners land in an empty profile and discover completeness only when applying.
- No guided “welcome” that explains: verify → complete profile when ready → browse courses.

### 3. Recommended improvements

| Priority | Improvement |
|---|---|
| Quick win | Visual polish of register/login/pending (brand hero strip, clearer steps, support email). |
| Quick win | Password helper text aligned to server policy; show/hide password control. |
| High impact | Step indicator: Account → Verify email → Verify mobile → Ready. |
| High impact | Soft onboarding after first full auth: optional personal name + display name only; skip allowed. |
| High impact | Apply flow already requires profile completeness — keep that gate; do **not** require full profile at register. |
| Later | Welcome email (new transactional event) — needs Decision Log if not already covered by identity mail. |

**Do not:** collect medical council, address, or qualifications on the register form.

### 4. Database impact

- None for UI polish.
- Optional onboarding: no new tables if using existing `learner_profiles` columns.
- Welcome email: outbox event + template only (no schema), if approved.

### 5. Permission impact

- None. Existing `profile.*_own` and identity permissions suffice.

### 6. Migration requirements

- None expected for Slice 1 core.

### 7. Dependencies

- Branding tokens (Slice 3) improve visual quality but are not required to start.
- Profile stub ensure already shipped (staff `/profile` fix).

### 8. Suggested implementation order

1. Auth UI polish + password UX.  
2. Verification/pending copy and step chrome.  
3. Soft post-login onboarding prompt (Decision `PX-ONBOARD-1`).  
4. Optional welcome letter.

---

## Slice 2 — Learner Experience & Progress Tracking

### 1. Current state

- Dashboard (`/dashboard`): next steps, updates teaser, enrolled course cards with `%` and continue link, upcoming live sessions, certificate counts, application table for non-enrolled apps.
- Player outline: progress bar, chapter list, lesson states, continue CTA.
- System progress = completed mandatory/all lessons over total (from `content_progress`).
- Certificates list/show/PDF/verify exist.
- Q&A on lesson pages exists; outline does not show open-question counts.

### 2. User experience gaps

- Dashboard still mixes “admission admin” (applications table) with “I am studying”.
- Progress is numeric; little narrative (“Chapter 2 of 4”, “Quiz unlocked”, “Certificate ready”).
- No personal goals, study plan, or reflection — only system-calculated completion.
- Achievements are effectively certificates only; no intermediate milestones UI.
- Live sessions appear when present; empty state is weak.
- Continue learning works but is easy to miss among admission chrome.

### 3. Recommended improvements

**A. System-calculated progress (safe; uses existing data)**

| Improvement | Notes |
|---|---|
| Stronger course cards | Cover, chapter title of current lesson, “X of Y lessons”, clear primary CTA. |
| Progress narrative | “In progress · Chapter {n}”, “Ready for quiz”, “Complete — view certificate”. |
| Separate sections | **Studying now** vs **Applications** vs **Certificates**. |
| Outline polish | Optional open Q&A count per design; celebration state when all mandatory done. |

**B. Learner-owned progress (requires `PX-GOALS-1`)**

| Improvement | Notes |
|---|---|
| Simple goals | e.g. “Finish Chapter 1 by Friday” — learner-owned, not certificate rules. |
| Weekly focus | Optional one-line intention; not enforced by domain. |
| Reflections | Out of first cut; do not conflate with faculty Q&A. |

Keep **system progress** authoritative for release rules, certificates, and assessments. Learner-owned features must never unlock content or alter `content_progress`.

### 4. Database impact

- System polish: none (or tiny presentation-only).
- Goals: new table e.g. `learner_goals` (`enrolment_id`, text, target_date, status) — only after Decision Log.

### 5. Permission impact

- Goals: likely `learning.goal.manage_own` under existing `learning.content.access` ownership checks.
- No Finance access to goals/notes.

### 6. Migration requirements

- None for system-progress UX.
- Additive migration for goals if approved.

### 7. Dependencies

- Course covers (RC1) already available for cards.
- Certificate issuance path unchanged.

### 8. Suggested implementation order

1. Dashboard information architecture (Studying / Applications / Certificates).  
2. Richer progress copy and continue CTA hierarchy.  
3. Outline empty/complete states.  
4. Goals only after `PX-GOALS-1`.

---

## Slice 3 — Academy Identity & Branding

### 1. Current state

- Env-driven `AcademyBranding`: name, logo URL, primary colour, support email, certificate issuer (`ACADEMY_*`).
- Applied to layout CSS vars, catalogue, login, certificates HTML/PDF, email layout.
- Single deployment = one academy. No tenant table. No admin branding UI.
- Some email subjects still hardcode “Academy” rather than `$branding->name`.

### 2. User experience gaps

- Redeploy/env edit required to change identity — operators cannot self-serve.
- Logo may be a path; no guided upload for brand mark (course covers exist; brand logo does not).
- Homepage “Why this academy” copy is product-generic, not customer-editable (acceptable if template stays code-owned).
- Certificate and email branding are good but incomplete (subject lines, from-name consistency).

### 3. Recommended improvements

| Priority | Improvement |
|---|---|
| Quick win | Replace hardcoded “Academy” subjects with branding name. |
| Quick win | Document operator checklist for `ACADEMY_*` on deploy. |
| High impact | Super Admin **Brand settings** screen writing to a `academy_settings` row or env-backed store — **still one academy**. |
| High impact | Optional brand logo upload to private learning store, served like covers. |
| Hold | Per-course branding — out of scope; conflicts with single academy product. |

**Do not introduce multi-tenancy**, organisation workspaces, or white-label reseller models.

### 4. Database impact

- Env-only polish: none.
- Settings UI: small `academy_branding` / `academy_settings` singleton table **or** continue env (Decision `PX-BRAND-1`).

### 5. Permission impact

- New `academy.branding.edit` for Super Admin only (suggested). Course Admin must not change academy-wide brand without product sign-off.

### 6. Migration requirements

- Only if settings table or logo object-key columns are approved.

### 7. Dependencies

- Existing cover storage patterns can be reused for logo.
- Email layout already accepts branding.

### 8. Suggested implementation order

1. Subject/copy consistency.  
2. `PX-BRAND-1` decision.  
3. Settings UI + optional logo upload.  
4. Homepage copy remains code-owned unless product later asks for editable fields.

---

## Slice 4 — Course Creator Experience

### 1. Current state

- Flow: New course (code, slug, title) → course page (cover, editions) → edition form → chapters/lessons → eligibility/documents → publish → batch.
- UI already uses **Chapter** in several places; domain remains `Module`.
- Lesson types menu is strong (text, rich text, PDF, video modes, podcast, audio, live, quiz).
- Publish locks the edition; clone creates Version N+1. Messaging improved in RC1 but still not a wizard.
- Faculty home lists courses, live sessions, Q&A.

### 2. User experience gaps

- Feels like CMS navigation, not “create a programme”.
- Slug, version number, lock flags still leak into creator confidence.
- No single readiness checklist before Publish (lessons empty? quiz without questions? fee set? eligibility?).
- No “Preview as learner” that opens the player under a synthetic/read-only path.
- Empty states for new courses are weak.
- Terminology not fully consistent: Edition vs Version, Batch vs Cohort.

### 3. Recommended improvements

| Priority | Improvement |
|---|---|
| Quick win | Terminology pass: Edition (not Version), Chapter, Lesson, Quiz, Batch or Cohort (`PX-TERM-1`). |
| Quick win | Publish confirm copy: what locking means; offer Create Edition N+1 path. |
| High impact | Creation **wizard** shell: Details → Chapters → Eligibility → Review & Publish (routes can stay; chrome becomes sequential). |
| High impact | **Readiness checklist** on Review step (computed from existing data). |
| High impact | Empty states with one primary action (“Add first chapter”). |
| Later | Preview as learner (scoped; no fake enrolment in production without decision). |

**Preferred customer language**

| UI | Storage |
|---|---|
| Course | `courses` |
| Edition | `course_versions` |
| Chapter | `modules` |
| Lesson / Quiz | `content_items` |
| Batch / Cohort | `batches` |

### 4. Database impact

- Terminology/wizard chrome: none.
- Readiness checklist: derived queries only.
- Preview-as-learner: likely no schema if read-only admin preview of published/draft structure; stop if it requires a fake enrolment.

### 5. Permission impact

- None for terminology/checklist.
- Preview uses existing `course.view_assigned` + scope.

### 6. Migration requirements

- None for core Slice 4.

### 7. Dependencies

- Eligibility UI (RC1) already exists.
- Q&A does not affect authoring.

### 8. Suggested implementation order

1. Terminology sweep + empty states.  
2. Review & Publish checklist.  
3. Wizard chrome over existing pages.  
4. Preview-as-learner after product confirms approach.

---

## Slice 5 — Academy Operations & Analytics

### 1. Current state

- Course Admin home: total courses, published, active batches, learners enrolled (scoped).
- Faculty: assigned courses, learners enrolled, live sessions, questions.
- Finance: reconciliation exception queue — not a revenue dashboard.
- No Reporting domain implementation; no engagement time-series.

### 2. User experience gaps

- Operators cannot answer: completion rate, quiz pass rate, who stalled, which edition converts.
- No activity timeline (admissions, publishes, certificates, Q&A) beyond faculty’s thin recent list.
- Revenue visibility for Course Admin is ambiguous vs Finance SoD.

### 3. Recommended improvements

**Dashboard (Course Admin / Faculty — scoped)**

| Card / block | Source | Notes |
|---|---|---|
| Courses / published / batches / learners | Existing | Keep; polish presentation. |
| Open questions | `learning_questions` | Already on faculty home. |
| Completions (count of issued certificates) | `certificates` | Aggregate only. |
| Payment summary | `payments` successful amounts | **Only if `PX-ANALYTICS-1` allows**; else Finance only. |

**Course analytics (per course in scope)**

| Metric | Source |
|---|---|
| Enrolments | `enrolments` by lifecycle |
| Completion | certificates or % mandatory complete |
| Assessment attempts / pass rate | `assessment_attempts` |
| Lesson engagement | `content_progress` last_accessed / completed counts — not watch-% until players write it |

**Operational timeline**

- Append-only feed from existing audit/outbox summaries (admitted, published, certificate issued, question asked) — no new event bus.

### 4. Database impact

- Prefer read models / SQL aggregates. No warehouse.
- Optional materialised daily stats table only if performance requires it (Decision).

### 5. Permission impact

- Reuse `course.view_assigned` + scope.
- Finance data stays behind Finance permissions unless aggregate approved.
- Document metadata remains forbidden to Finance.

### 6. Migration requirements

- None for v1 aggregates.
- Optional stats table later.

### 7. Dependencies

- Enrolment and certificate data already exist.
- Q&A counts already queryable.

### 8. Suggested implementation order

1. Polish existing Course Admin / Faculty cards.  
2. Per-course analytics page (enrolment + completion + assessment).  
3. Activity timeline.  
4. Revenue aggregate only after `PX-ANALYTICS-1`.

---

## Slice 6 — Learner Delight Features

### 1. Current state

- Certificates: list, show, PDF, public verify.
- Q&A: ask/respond (not “delight”, but academic support).
- No bookmarks, private notes, or saved items.
- No achievement badges beyond certificates.

### 2. User experience gaps

- Learners cannot save a lesson for later without remembering the URL.
- No private scratchpad for clinical study notes on a lesson.
- Certificate experience is correct but not ceremonial (share image, print emphasis).
- Achievements lack intermediate recognition (e.g. “Chapter complete”).

### 3. Recommended improvements

| Feature | Approach | Constraint |
|---|---|---|
| Bookmarks | `learner_bookmarks` on enrolment + content | Private; own only |
| Private notes | `learner_lesson_notes` text on enrolment + content | Private; never in Q&A email; Finance forbidden |
| Saved items | Alias of bookmarks or “Continue later” list on dashboard | No social share shelf |
| Certificate delight | Better show page, download CTA, verify prominence | No QR/revoke redesign unless already planned |
| Achievements presentation | UI derived from chapter completion + certificates | Do not invent badge economy that affects access |

**Do not introduce:** annotations on PDF, collaborative notes, likes, peer comments, or forum posts.

### 4. Database impact

- Bookmarks: additive table + unique (enrolment_id, content_id).
- Notes: additive table + unique (enrolment_id, content_id); body length cap; audit without body.
- Achievements UI: often no schema if derived.

### 5. Permission impact

- `learning.bookmark.manage_own`, `learning.note.manage_own` (suggested).
- Repository-layer ownership checks; never expose to Finance/Reviewer.

### 6. Migration requirements

- Yes for bookmarks/notes after `PX-NOTES-1`.

### 7. Dependencies

- Active enrolment + player access policies (reuse).
- Distinct from Q&A (`learning_questions`).

### 8. Suggested implementation order

1. Certificate show/PDF polish (no schema).  
2. Bookmarks (`PX-NOTES-1`).  
3. Private notes.  
4. Achievement presentation derived from progress.

---

## 3. Cross-slice synthesis

### Quick wins (low risk, ship early)

1. Auth register/login visual polish + password guidance.  
2. Email/certificate subjects use `ACADEMY_NAME`.  
3. Dashboard sectioning: Studying vs Applications vs Certificates.  
4. Admin terminology pass (Edition / Chapter / Lesson / Quiz).  
5. Publish readiness checklist (read-only derived).  
6. Certificate show-page polish.

### High-impact changes

1. Soft onboarding after verify (name only).  
2. Learner progress narrative on dashboard and outline.  
3. Course creation wizard chrome + empty states.  
4. Per-course analytics for scoped admins.  
5. Brand settings (if `PX-BRAND-1` chooses UI over env).  
6. Bookmarks + private notes.

### Changes requiring architectural / product decisions

| Decision | Risk if skipped |
|---|---|
| `PX-ONBOARD-1` | Over-blocking registration or under-helping apply |
| `PX-BRAND-1` | Operators stuck on env edits; or accidental multi-tenant design |
| `PX-TERM-1` | Inconsistent UI language |
| `PX-ANALYTICS-1` | SoD breach if revenue shown wrongly |
| `PX-NOTES-1` / `PX-GOALS-1` | Schema and privacy without authority |
| Preview-as-learner mechanism | Fake enrolment vs read-only admin preview |

---

## 4. Recommended overall implementation order

```text
Wave A — Quick wins
  Auth polish → branding subject consistency → dashboard IA → terminology → publish checklist

Wave B — Learner narrative
  Progress copy + continue hierarchy → certificate delight → (optional) outline Q&A counts

Wave C — Creator confidence
  Wizard chrome → readiness checklist → empty states → (optional) preview

Wave D — Identity self-serve
  PX-BRAND-1 → brand settings / logo

Wave E — Operations
  Per-course analytics → activity timeline → (optional) revenue aggregate

Wave F — Delight
  PX-NOTES-1 → bookmarks → private notes → (optional) PX-GOALS-1 goals
```

Waves A–C do not require new Decision Log entries beyond terminology confirmation. Waves D–F do.

---

## 5. Permissions summary (expected)

| Area | Existing | Likely new (after Decision Log) |
|---|---|---|
| Profile / register | `profile.*_own`, identity.* | None |
| Learning progress UI | `learning.content.access` | None |
| Q&A | `learning.question.*` | None (already shipped) |
| Branding admin | — | `academy.branding.edit` |
| Analytics | `course.view_assigned` | Maybe `course.analytics.view_scoped` |
| Bookmarks / notes | — | `learning.bookmark.manage_own`, `learning.note.manage_own` |
| Goals | — | `learning.goal.manage_own` |

Finance still must not see document or lesson-note content. Reviewer still must not issue refunds.

---

## 6. Testing expectations (when implementation starts)

- HTTP: registration remains possible without profile; soft onboarding is skippable.
- Progress UI never unlocks content without `ModuleReleasePolicy` / player access.
- Branding settings (if built) are Super Admin only; one row / one academy.
- Analytics queries are scope-filtered; Finance aggregates respect SoD.
- Bookmarks/notes: owner isolation; Finance 403; no body in audit.
- No new Application / Enrolment / Payment transitions.
- Q&A behaviour unchanged unless a later slice explicitly extends it.

---

## 7. Out of scope reminder

AI features, chat, forum, mobile apps, marketplace, multi-tenancy, annotations, collaborative editing, and social features are **not** part of this plan.

---

## 8. Next step

Product Owner reviews this plan and:

1. Approves Wave A quick wins to proceed without new Decision Log entries (except optional `PX-TERM-1` confirmation).  
2. Records `PX-ONBOARD-1`, `PX-BRAND-1`, `PX-ANALYTICS-1`, `PX-NOTES-1`, `PX-GOALS-1` as needed before Waves D–F.  
3. Chooses Batch vs Cohort label (`PX-TERM-1`).

Do not start coding until the wave (or slice) is explicitly approved.
