# Product Experience Gap Assessment

**Status:** Assessment only. Not an SRS amendment. Not implementation authority.  
**Date:** 2026-09-13  
**Baseline:** `main` after merge of pull request #20 (production lesson authoring and player, PDF.js viewer, configured upload caps).  
**Authority:** If this note and the SRS disagree, the SRS wins. Faculty Q&A and a forum are **not** in the SRS. Do not build them from this document.  
**Storage model (unchanged):** `Course` → `CourseVersion` → `Module` → `ContentItem`. Customer language may say Course, Chapter, Lesson, Quiz. Do not add a Lesson table.

---

## 1. Executive Summary

The academy can admit a learner, take a payment, open a course, play lessons, and issue a certificate. That is a working product spine. It is not yet a commercial academy a visitor or a faculty member would recognise on first sight.

What a guest sees is a course list. What a course administrator sees is a table of codes and statuses. What a learner sees after admission is three more tables: applications, payments, enrolments. Emails that already send are plain text with operational subjects. There is no homepage, no course image, and no place a learner can ask a question about a lesson.

The lesson player is the part that already feels like a course. Outline labels say Text, PDF, Video, Quiz, and Live session. PDFs open in the lesson. That work should not be redone. The gap is everything around it: arrival, overview, visual identity, and the language of creating a course.

This assessment does not propose a new architecture, a new state machine, or a replacement for Module and ContentItem.

Two items in the requested target are **not approved to build**:

- Learner–faculty Q&A. Not in the SRS. A shape already exists as a proposal in [LEARNING_EXPERIENCE_ROADMAP.md](./LEARNING_EXPERIENCE_ROADMAP.md) §4. A Decision Log entry is required before any table, permission, or email.
- A forum. Do not build one inside the LMS. Integration requirements are already sketched in that same roadmap §5. Vendor, batch-versus-course mapping, and who may enter are still open.

One money item should not be slipped onto the Course Admin console without a decision. SRS `REQ-DASH-FIN-1` puts revenue on the Finance dashboard. Course Admin must not see payment line items, payer identity, or document data. A captured-amount total for courses in scope is a product choice, not something this note assumes.

---

## 2. Current Product Maturity Assessment

| Layer | Maturity | What a reviewer would say |
|---|---|---|
| Admission, payment, enrolment, certificates | Functional | The rules hold. The screens that wrap them still look like status tables. |
| Lesson player and authoring of lesson type | Usable for a demo of the lesson itself | Labels, upload, PDF viewer, live join card, and quiz are in place. |
| Course creation as a faculty task | Operational, not intuitive | Identity, draft version, curriculum, publish, and batch exist, but as separate technical pages. Eligibility and required documents have no admin screen. |
| Learner home | Incomplete against both the SRS and the target | `/dashboard` is an application tracker. Progress, thumbnails, current lesson, live sessions, pending quizzes, and certificates are not on it. |
| Public face | Catalogue only | `/courses` is the guest landing. There is no `/` homepage. |
| Email | Deliverable, not branded | Outbox and templates exist. Bodies are plain text. Registration mail is a token string, not a letter. |
| Community | Absent | No Q&A. No forum link. Support tickets in the SRS (`REQ-SUP-1`) are also not built. Do not treat tickets as a substitute for lesson questions. |

Screen inventory IDs used below are from [Academy_LMS_Screen_Inventory-3.md](../design/Academy_LMS_Screen_Inventory-3.md). Several approved screens are not built. That is a coverage gap, not a reason to invent screens the inventory does not name.

---

## 3. Screen-by-screen review

### 3.1 Course Admin dashboard

**Target:** an executive console — courses, published courses, active batches, learners enrolled, and a basic payment summary.  
**SRS neighbour:** `REQ-DASH-OPS-1` (CA-07) asks for active and upcoming courses and batches, learner counts, capacity, delayed modules, and upcoming sessions. It does not ask for revenue. Revenue is `REQ-DASH-FIN-1` (FA-01).

**What exists today**

- Course Admin lands on `/admin/courses` (screen family CA-01).
- The page is a table: course code, master title, raw status (`active` / `retired`), and Open.
- Helper text still says publish and batches arrive in a later work package. That is false. Publish and batch creation already exist on the version page.
- There are no summary counts, no cards, and no “what needs attention” strip.
- Finance lands on `/finance/reconciliation`, which lists reconciliation-pending payments and failed webhooks. It is an exception queue, not a revenue dashboard.

**What is missing**

- Overview cards for the requested counts.
- A published-versus-draft distinction at a glance. Course status is not the same as “has a published version”.
- Active batch count and enrolled learner count, scoped to courses the administrator may see (`course.view_assigned` plus course admin scope). A global count would leak other courses.
- A payment summary, if product wants it at all, as an aggregate only.
- The rest of CA-07 (capacity, delayed chapters, upcoming sessions) is also missing. Do not pretend the executive cards close that SRS item.

**UI components**

- Page title that names the academy console, not “Course administration” as a database heading.
- Five summary cards. Each card links to the existing list filtered to that slice, not to a new application.
- A course list underneath, still the system of record: title, published or draft, next batch, learner count. Keep the table; stop making the table the whole page.
- Remove the stale “later work package” sentence.
- Do not put payer names, application numbers, or document status on this page.

**Backend data (read-only)**

| Card | Source already in the database | Rule |
|---|---|---|
| Total courses | `courses` in the admin’s scope | Count courses, not versions. |
| Published | `courses.current_published_version_id IS NOT NULL` | Do not treat course status `active` as published. |
| Active batches | `batches` for those courses, status open/in progress as the batch machine already defines | Do not invent a new batch status. |
| Learners enrolled | `enrolments` for those batches where lifecycle is not cancelled | Admitted applications without an enrolment must not be counted. |
| Payment summary | `payments` with successful status, amount on the payment row, limited to applications for in-scope courses | Aggregate only. No line items. **Hold until product confirms Course Admin may see this.** Prefer the same aggregate on the Finance landing. |

**Recommended approach**

Add a read model and a card row above the existing course list. Do not add a reporting warehouse. Do not give Course Admin Finance permissions. If the payment card is not approved, ship the four count cards and leave money on Finance.

### 3.2 Course creation flow

**Target language:** Course, Chapters, Lessons, Quiz.  
**Stored model:** Course, CourseVersion, Module, ContentItem. Quiz is already `mcq_assessment`, labelled Quiz in the lesson menu.

**What exists today**

1. New course (`/admin/courses/new`): course code, public slug, master title.
2. Course page: a versions table (version number, title, raw status, locked yes/no).
3. Version page: a long form (title, description, objectives, audience, syllabus, delivery type, duration, fee, GST, currency, validity days, certificate type) plus Publish, Clone to Version N+1, and Create batch once locked.
4. Curriculum page: modules and lessons. Adding a lesson uses the customer menu (Text, Rich text, PDF, Video embed, Video link, Video upload, Podcast, Audio upload, Live session, Quiz). Locked versions correctly refuse edits and say to create Version N+1.

**Is it intuitive for a faculty member?**

No. A creator who has never seen the schema has to understand slug, version number, locked, admission mode, and clone-before-edit before they can publish a chapter. The lesson type menu is the one step that already matches the target.

**Technical concepts still on screen**

- Public slug, with a kebab-case hint.
- Version number, locked, immutable, CourseVersion, admission mode, cloned-from version id.
- Module (not Chapter). Release rule values `Immediate` and `Sequential` with no explanation.
- Publish is a single button with no checklist and no confirmation of what locking means.
- Certificate type, delivery type, and currency are free-text fields.
- Eligibility rules and required documents are stored and shown on the public course page when seeded. There is no admin form for them. Screen inventory CA-02 includes those steps. A faculty member cannot complete an honest course from the UI alone.

**Lesson creation**

Clear enough once the creator is on the curriculum page: choose a type, fill the panel, save. Quiz has a separate “Edit quiz” path into the question bank and assessment settings. That split is correct (a quiz is not just a title) but there is no cue that the lesson is incomplete until questions exist.

**Publishing**

The button exists and locking is real. The workflow is not clear. There is no review step, no “this will lock the syllabus” explanation in faculty language, and no warning that eligibility and documents were never set in this UI.

**Gaps**

| Kind | Gap |
|---|---|
| UI | No wizard. Identity, public description, chapters, quiz, and publish are disconnected pages. |
| Terminology | Module, CourseVersion, slug, locked, Version N+1. Lesson and Quiz are already correct on the curriculum page. |
| Navigation | No step indicator. Version page does not say “next: add chapters”. Course list still claims batches are not built. |
| Workflow | Cannot set eligibility or required documents. Publish can lock a draft that has no lessons. Changing a live course is explained as a database constraint, not as “publish a new edition”. |

**Recommended approach**

Keep the stored hierarchy. Change labels only: Module → Chapter in admin and learner chrome; CourseVersion → “edition” or “version” in one sentence, not as the page title. Generate the slug from the title and hide it behind “web address”. Group the existing version form into sections (About the course, Fee, Certificate) rather than one field dump. Add a publish panel that lists what is present and what is missing (chapters, lessons, fee) without changing the publish state machine. Eligibility and document requirements need their own admin screens before go-live; they are approved inventory steps, not new product ideas. Do not add drag-and-drop until the words and the missing steps are right.

### 3.3 Course thumbnails

**Can courses have images today?** No. `courses` and `course_versions` have no image column. `thumbnail_ref` in the technical architecture is a video-processing field, not a course cover. Do not reuse it.

**Where a thumbnail should appear**

| Surface | Today | Placement |
|---|---|---|
| Catalogue cards (`/courses`, G-01) | Text card: code, delivery, duration, audience, fee | Image on top of the existing card. |
| Course detail (G-02) | Text hero (title, fee, description) | Image in the existing hero, not a second hero. |
| Learner dashboard (L-01) | Enrolment table | Image on the course card this assessment asks for. |
| Course outline | Title and progress bar | Small image beside the title. Optional; the player already has a visual stage. |
| Admin course list and version page | Text only | Small preview so the creator can see what the public will see. |

Do not put the image on payment, document review, or finance screens.

**Storage**

Course images are marketing, not credential documents. They must not share a permission path that lets Finance or an anonymous visitor open a document. They also must not become a permanent public signed URL pattern that people copy onto documents.

Recommended shape, pending a Decision Log choice on public versus signed:

- Store an object key and a display filename on the **course** (stable identity), not on each version, unless product wants the cover to change per edition. Edition-specific covers are not specified. Default to the course.
- Upload through the existing private learning-media pattern (validate, store, short-lived URL). Do not put files in `public/`.
- Catalogue and dashboard render a short-lived URL at page generation (same 10–15 minute rule). That is consistent with current storage. The cost is that a cached HTML page cannot pin the image forever. That is acceptable for go-live.
- A separate public marketing prefix would look better on a storefront and is **not** approved. Do not add one in the first slice.
- Size and crop ratio are not in the SRS. A 16:9 card crop is a design convention only. Do not treat a pixel size as a product requirement until design confirms it.
- Upload cap should be configuration, in the same family as the existing media caps, and must not reuse the 500 MB video cap for a cover image. The number is unspecified. Stop and set it before coding.

**Metadata**

`image_object_key`, original filename, content type, byte size, updated-at. No signed URL column.

### 3.4 Learner dashboard

**Target:** “I am enrolled in a professional course.”  
**What exists:** `/dashboard` (L-01 partial). Heading “My learning”. Sections are Applications, Payments, and Enrolments, each a table. An active enrolment has Continue learning, which opens the outline, not a named lesson. Empty enrolments say enrolment is created only after admission — correct, and not a sentence a learner should need.

**Progress** exists on the course outline (`completed / total` and a bar). It is not on the dashboard. The outline continues to the first accessible incomplete lesson, not the last opened one. `content_progress.last_accessed_at` exists and is not used as the continue target. An older planning note left open whether the bar’s denominator is all lessons or mandatory lessons only, and whether that matches certificate eligibility. The dashboard must use the same definition as the outline. Do not label the bar “course complete” unless certificate eligibility agrees.

**What the target asks for, against current screens**

| Need | Now | Gap |
|---|---|---|
| My enrolled courses | Enrolment rows | No course cards. Application and payment tables dominate the page. |
| Thumbnail | None | Blocked on §3.3. |
| Progress percentage | Outline only | Copy the existing count; do not invent a second formula. |
| Continue learning | Button to the outline | Should name the lesson and open it. |
| Current lesson | Not shown | Title of the continue target. |
| Upcoming live sessions | Inside the outline as a lesson, if the learner opens it | No schedule on the dashboard. Live start time is already stored on the lesson. Screen L-03 (live schedule) is not built. |
| Assessments pending | Quiz is a lesson; attempt is a separate screen | Dashboard does not list an in-progress attempt or an unopened quiz. “Pending” is not defined (not started, in progress, or failed with retries left). Do not pick one in code until product says. |
| Certificates earned | Listed only under that enrolment’s certificates page | Not on the dashboard. |

**UX issues**

- Three tables before the course.
- Raw version label in the enrolment column (`Version / batch`).
- Payments and applications are necessary during admission and should collapse once the learner has an active enrolment, not sit as the main event.
- No visual distinction between “you are applying” and “you are studying”.

**Recommended approach**

Keep applications and payments as a compact “needs you” strip when a required action exists (the dashboard already computes required actions). Below that, one card per enrolment: title, thumbnail when it exists, progress bar using the outline’s counts, current lesson name, Continue. A second short list for upcoming live lessons in enrolled courses (join time already stored; format in India time, as the player already does). Certificates as a count and a link to the existing certificate page. Do not build L-03 as a new product until the dashboard section is not enough. Do not change enrolment states.

### 3.5 Email

**Architecture:** unchanged. Durable outbox, code-owned templates, SMTP or local-file adapter, `text/plain` only (`SmtpEmailAdapter`). No HTML part. No logo. Academy name is not in the body unless a variable happens to carry it.

**Against the four moments requested**

| Moment | Event today | Subject today | Body quality |
|---|---|---|---|
| Verify your email | Identity token worker, not the transactional registry | “Verify your email” | Body is `token=` plus the raw token. Not a sentence. Not a button. A recipient cannot tell what to do. |
| Application received | `application.submitted` | “Application submitted — {number}” | Plain text. Correct facts. Reads as a system receipt. |
| You have been admitted | `application.admitted` and a second mail `enrolment.created` | “Application admitted — {number}” and “Enrolment confirmed — {title}” | Two emails for one human moment. The enrolment mail warns that payment alone is not enough, which is accurate and alarming if they have already paid and been admitted. |
| Certificate ready | `certificate.issued` | “Certificate issued — {title}” | Includes a certificate link. Closest to the target. Still plain text. |

Other mails already exist (corrections, payment required, payment failed, payment under verification, payment received, rejection). They are not in the four-moment brief. They have the same plain-text problem. Do not drop them when branding is added.

**Branding and HTML**

None. From-name can be set on the SMTP adapter. Templates do not use `AcademyBranding` (name, logo, support email, primary colour), which already exists for the website.

**Missing events versus `REQ-NOTIF-1`**

The registry does not cover course start, new chapter released, live session reminder or reschedule, assessment opening, deadline, result, or attempt limit. Those are in the SRS. They are not required to make the four moments above feel human. Do not start them in the same change as a template redesign, and do not invent reminder timing.

**In-app notifications**

`REQ-NOTIF-1` also requires an in-application feed (L-09). It is not built. Email branding does not close that item.

**Recommended approach**

Wrap the existing template strings in a single branded layout (academy name, logo URL, one primary action link, support email). Keep the outbox, event types, and variable allow-list. Fix the verification mail first: a full verify URL and one sentence, not a token fragment. Soften the admitted and certificate subjects to the words product asked for, without merging the two admission emails until product confirms one mail is enough (they fire for different domain events and the second one is the enrolment fact). HTML as an alternative part, with the current plain text kept, so a client that strips HTML still gets the letter. No editable template CMS (`SA-04` is deferred). No new events in the first pass except if verification is classified as a missing letter rather than a new event — it already sends; the body is the defect.

### 3.6 Landing page / academy storefront

**Does a public landing page exist?** No. There is no `GET /`. The brand link for a guest goes to `/courses`.

**Does course discovery exist?** A list of published courses. Cards show code, delivery type, duration, title, audience, GST-inclusive fee, and View course. There is no search, no filter, and no next-batch line. SRS `REQ-CAT-1` also asks for key eligibility, next batch, certificate type, and faculty on the card. Faculty is not a stored catalogue field in the current course identity. Do not invent a faculty name on the card.

The course detail page is stronger: description, objectives, audience, syllabus, eligibility when present, fee, batches, and apply. It is still text-only.

**Can a visitor understand the academy?** Only if the course titles explain it. The shell shows a logo and a configurable academy name (default “Academy LMS”). There is no sentence about obesity and metabolic health, no reason to trust the certificate, and no path except “browse courses” by arriving at the catalogue directly.

**Missing for the requested homepage (not a marketing site)**

- A route that is the academy front door.
- Hero with product-owned headline. “Learn from experts” is not in the SRS. It may be used only as approved copy. Do not hard-code a medical claim that has not been signed off.
- Browse Courses, linking to the existing `/courses`.
- Featured courses. There is no featured flag. Product must say how a course is featured (manual, or simply the published list, capped). A count is not specified. Showing the published catalogue, or a short slice of it, is enough. Do not invent a ranking.
- Why choose us. Not in the SRS. Needs approved sentences. Until then, the homepage should not fabricate benefits (CME credit counts, partner hospitals, learner numbers).
- Certificates. Public verification already exists (`/verify/certificates/{number}`). A short explanation and a link to verify is honest. A gallery of sample certificates is not specified.

**Recommended approach**

One public template using `AcademyBranding` and the existing catalogue query. Featured courses are the published list until a featured flag is approved. Static sections for hero, why choose us, and certificates use copy supplied by product, not generated from medical assumptions. Catalogue cards can gain a thumbnail when §3.3 exists. Do not build a CMS. Do not add filters until `REQ-CAT-1` filter fields are confirmed (eligibility and next batch can come from data already on the course page; faculty cannot).

### 3.7 Learner questions / faculty messaging

**Not an implementation proposal.** Do not build from this section.

**What exists**

- No question thread, no faculty inbox, no lesson discussion.
- Support tickets are in the SRS and are not implemented. A ticket is a helpdesk item (L-08, S-01). It is the wrong object for “I don’t understand this slide”.
- Credential Reviewer must not gain an inbox for lesson questions (segregation). Course Admin must not use this path to see credential documents.
- Notification architecture can carry a new event later. It does not have a question event now.

**Required architecture if approved later**

Keep it asynchronous. One question anchored to an enrolment and a lesson (`content_id`), not a chat channel. Status open / answered / closed. Faculty reply from course or batch scope, not from a global admin. No websockets. No attachment in the first decision unless product asks — attachments become a document-storage problem.

The roadmap §4 row shape (`learning.question.create_own`, outbox `learning.question.asked` and `learning.question.answered`, audit without clinical body in logs) is a reasonable sketch. It is not approved.

**Permission model (open)**

Who may answer is unspecified: assigned faculty, any Course Admin on that course, or someone else. Do not reuse Credential Reviewer. Do not grant Finance. A learner may ask only on a lesson they can already open.

**Notifications**

If approved, two outbox types, idempotent on question id (and reply id for the answer). Do not email on every keystroke. Do not put the full question body in logs.

**Stop condition**

Decision Log must name the answering role, whether a thread is one reply or many, and whether this is in scope for go-live. Until then it stays out of the implementation sequence below.

### 3.8 Discussion forum integration

**Not an implementation proposal.** Do not build a forum in this repository.

**What exists**

Nothing. No link, no SSO, no group key.

**Where a link should appear, if approved**

- Learner course outline: one “Community” action, not a widget that loads forum content inside the LMS.
- Not on the public catalogue (membership is an enrolment fact).
- Not on finance or document-review screens.
- Course Admin may need a “open this cohort’s forum” link later. That is secondary.

**SSO**

The LMS should assert identity. The forum must not store the LMS password. Map one forum user to `user_id`, not to email (emails change). A short-lived one-time code on a server redirect, as already sketched at `/learning/enrolments/{id}/community`, is the right seam. Do not embed the forum in an iframe for the first integration.

**Course / community mapping**

Unresolved, and the roadmap recommends **batch**, not course: faculty and live cohorts are batch-scoped. This assessment agrees with that recommendation and does not treat it as decided. Catalogue identity stays the course. Store only a `forum_group_key` on the batch (or version, if product chooses course-level). Do not sync posts into MySQL.

**Membership**

Add when enrolment is Active. Remove or archive on Withdrawn, Access Expired, Cancelled, Refunded. A sync job, idempotent, not only a browser click. Scheduled and unpaid enrolments should not enter. That default is not approved.

**Vendor**

Evaluate a mature secure open-source forum. **Discourse** is the candidate that matches that brief: self-hosted, group membership, SSO via a signed payload, category or group per cohort, and an established security model. **Flarum** is lighter and has less established SSO and cohort membership for this use. Proprietary hosts (for example Circle) do not meet the open-source constraint in the brief.

This note does not select Discourse. Selection is a Decision Log item (hosting, upgrades, data location, and whether the forum is in the same trust boundary as learner health discussions). Forum mail stays in the forum. Do not mirror every post into `REQ-NOTIF-1`.

---

## 4. Feature gap matrix

| Area | Current state | Gap | Priority | Recommended approach |
|---|---|---|---|---|
| Course Admin overview | Table at `/admin/courses`; stale “later work package” copy | No executive counts; CA-07 not built | High for demo, low risk for counts | Cards over the existing list, scoped. No payment line items. Revenue only if product assigns it away from Finance. |
| Course creation language | Lesson menu is customer-facing; rest of the builder is schema language | Module, version, slug, locked, N+1 | High for demo, low risk | Labels and sectioning only. Keep Module and ContentItem in the database. |
| Course creation workflow | Version form, curriculum, publish, batch. No eligibility or document admin UI | Faculty cannot finish a real course. Publish has no review | High for production, medium risk | Add the missing CA-02 steps. Publish panel explains locking. Do not change the publish transition. |
| Course images | Not stored | Catalogue, detail, dashboard, and admin have no visual identity | High for demo, medium risk | Object key on the course. Signed URL at render. No public bucket unless decided. |
| Learner dashboard | Application, payment, and enrolment tables | Does not feel like a course home. Progress and certificates live elsewhere | High for demo, medium risk | Enrolment cards. Reuse outline progress. Continue to a named lesson. Admission tables only when an action is required. |
| Upcoming live sessions | Shown only inside the outline | No dashboard list. L-03 not built | Medium | A short list from existing live-session fields. Not a new entity. |
| Pending assessments | Attempt screen exists | Not on the dashboard. “Pending” undefined | Medium | Do not implement until “pending” is defined. |
| Email: verify | Sends | Body is a raw token | High for production, low risk | Full URL and a sentence in the existing identity mail. |
| Email: received, admitted, certificate | Sends, plain text, two admission mails | No brand, operational subjects | High for demo, low risk | One branded layout around current templates. Do not merge events without a decision. |
| Email: other REQ-NOTIF-1 events | Not registered | Reminders, results, course start | Lower than the four moments | Later. Do not invent timing. |
| Homepage | None. Catalogue is the front door | Visitor cannot tell what the academy is | High for demo, low risk once copy exists | `GET /` using branding and published courses. Approved copy required for hero and “why choose us”. |
| Catalogue cards | Functional text cards | Missing image, next batch, eligibility line from REQ-CAT-1 | Medium | Image when available. Next batch and eligibility from data already on the detail page. No faculty field. |
| Learner Q&A | None | Not in SRS | Not in the build sequence | Decision Log first. Sketch in the learning roadmap §4. |
| Forum | None | Must not be custom-built | Not in the build sequence | Decision Log first. External Discourse-class forum, batch group, SSO, no content sync. |
| In-app notification centre (L-09) | None | SRS REQ-NOTIF-1 | Medium, after email letters are human | Do not bundle into the homepage. |
| Faculty dashboard (F-01) | None | SRS REQ-DASH-FAC-1 | After Course Admin counts | Not this experience slice. |

---

## 5. Recommended implementation sequence

Ordered by what a customer or a demo sees, then by what would embarrass a production launch, then by risk. Nothing in this list replaces Course, Module, or ContentItem, and nothing changes a state machine.

**Do first (demo, low structural risk)**

1. **Language and stale copy.** Chapter in the UI. Hide slug. Delete the “batches arrive later” sentence. Explain Version N+1 as a new edition. No migration.
2. **Homepage**, blocked on approved sentences for the hero and “why choose us”. Featured courses = published catalogue until a featured rule is approved. Certificates section links to public verification.
3. **Email letters** for verify, application received, admitted, and certificate ready. Branded HTML plus the current plain text. Fix the verification body. Do not add reminder events.

**Do next (demo quality, some new reads, no new workflow)**

4. **Learner dashboard cards.** Progress and continue-target from the player query that already exists. Certificates link. Applications and payments only as a “your next step” strip. Hold “assessments pending” until that phrase is defined.
5. **Course Admin count cards** (courses, published, active batches, enrolled learners), scoped. Payment total only after an explicit decision; otherwise put a total on the Finance landing later.

**Then (visible, needs a small schema and a storage decision)**

6. **Course thumbnail** on catalogue, course detail, learner card, and admin preview. Object key. Signed URL. Stop if the upload cap or public-versus-signed choice is still open.

**Then (production authoring, higher care, still no new states)**

7. **Authoring sequence polish:** section the version form, publish checklist in faculty language, eligibility and required-document screens that inventory already names. Publish still locks the version. Edits after lock still mean Version N+1.

**Not in this sequence**

- Q&A and forum, until a Decision Log names the answering role, the vendor, and batch versus course.
- In-app notification centre, live-session reminder emails, faculty at-risk dashboard, and CA-07 capacity views. Real, and not the first refinement.
- A CMS, a marketing site, or a custom forum.

---

## 6. Screens requiring redesign

These pages are the wrong object for the person using them. Relayout. Do not throw away the underlying actions.

| Screen | Why redesign |
|---|---|
| `/dashboard` (L-01) | Status tables, not a course home. |
| `/admin/courses` (CA-01 / stand-in for CA-07) | Database list presented as the console. |
| `/admin/courses/new` and version page (CA-02) | One technical form. No steps. Slug and version are the subject. |
| Public entry (no G-home; G-01 is the front door) | A visitor never meets the academy. |
| Verification email | Not a screen, but the registration moment fails as correspondence. |

Course outline and lesson player should not be redesigned. They already carry lesson language, progress, and the content types that were just shipped.

---

## 7. Screens requiring minor polish

| Screen | Polish |
|---|---|
| Curriculum (`/admin/courses/.../curriculum`) | Rename Module to Chapter in labels. Explain Immediate versus Sequential in one line. Keep the lesson type menu. |
| Course admin course page | Human status instead of raw `active` and locked yes/no. Link labels: Edit edition, View published. |
| Version page when locked | Replace “CourseVersion is locked / immutable” with “This edition is published. Changes need a new edition.” Keep the clone action. |
| Catalogue cards and course detail | Room for a thumbnail. Show next batch and certificate type already in the data. Drop nothing that is correct. |
| Learner outline | Continue can name the lesson. Progress label must not say complete unless certificates agree. |
| Certificate list | Fine as a destination. Surface a link from the dashboard rather than rewriting the page. |
| Finance reconciliation | Leave it as an exception queue. Do not dress it up as the academy console. |
| Login, register, application, payment, reviewer queue | Out of this refinement. They are workflows, not the storefront. |

---

## 8. Backend and data requirements

No new state machines. No Enrolment created except on Admitted. Published CourseVersion stays immutable; a thumbnail on the course identity does not unlock a published version. A cover on the version would, and should wait unless product insists the cover is edition-specific.

| Need | Data | New? |
|---|---|---|
| Admin counts | Scoped queries on `courses`, `course_versions`, `batches`, `enrolments` | Query only |
| Payment summary | Successful `payments` summed by in-scope application | Query only, permission decision first. Course Admin must not gain `finance.payment.view` as a side effect. |
| Learner cards | Enrolment + course title + outline completed/total + first incomplete accessible lesson title | Query. Same completion definition as the outline. |
| Live sessions on the dashboard | `content_items` live start for the enrolled version, future only | Query. No new lesson type. |
| Certificates on the dashboard | Existing certificate list by enrolment | Query. |
| Homepage featured courses | Published catalogue | Query, until a featured flag is approved. |
| Course image | Object key, filename, MIME, size on `courses` | Migration. Private object. Signed URL not stored. Upload cap unspecified — ask before coding. |
| Email layout | Existing template strings plus branding already in config | No new event types for the four moments. Verification body must include a URL. |
| Eligibility and document requirements in admin | Tables already exist and are shown publicly when seeded | Admin write screens. Audit on change. Locked version still refuses edits. |
| Q&A | Not modelled | Do not migrate. |
| Forum | Optional `forum_group_key` later | Do not migrate. |

**Open questions (do not resolve by assumption)**

1. May Course Admin see a captured-amount total, or does that stay on Finance (`REQ-DASH-FIN-1`)?
2. What is a “pending” assessment: not started, in progress, or failed with attempts remaining?
3. Is progress “all lessons” or “mandatory lessons”, and must the bar match certificate eligibility before it says complete?
4. Is a course image on the course or on the edition? What is the upload cap?
5. May catalogue images be served from a public marketing prefix, or only as short-lived signed URLs?
6. What are the approved hero and “why choose us” sentences? Which courses are featured, if not “all published”?
7. Should admission and enrolment stay two emails?
8. Who answers a lesson question, and is a forum in scope before go-live? If a forum is in scope, is the cohort the batch?

---

*Assessment only. No application code, migrations, or configuration changes.*
