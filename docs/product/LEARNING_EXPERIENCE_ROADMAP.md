# Learning Experience Roadmap

**Status:** Planning note for Phase 2 learning UX. Not an SRS amendment.  
**Date:** 2026-09-13  
**Product correction (2026-09-13):** Live classes by join link (Google Meet / Zoom) are required. Video is three creator choices — link, embed, upload — not one mixed “Video” type. Podcast and audio upload are separate. See §1.  
**Code baseline:** Course → Module → `ContentItem`; learner player; MCQ assessments; completion certificates.  
**Authority:** SRS v6.1 §4.3 (`REQ-MOD-1`–`REQ-MOD-4`), §12 notifications (`REQ-NOTIF-1`). If this note and the SRS disagree, the SRS wins until a Decision Log entry says otherwise.

**Rule for implementers:** Do not add states to Application / Enrolment / Payment machines. Published or otherwise locked `CourseVersion` rows stay immutable — new lesson types are authored on a Draft or Version N+1. Browser payment callbacks remain untrusted. New async behaviour must be idempotent and audited.

---

## What exists today

| Area | Implemented | Not implemented |
|---|---|---|
| Hierarchy | `Course` → `CourseVersion` → `Module` → `ContentItem` (sequence). There is no Lesson entity. | Multi-block lessons (text + video as one learner page). |
| Types (`ContentItemType`) | `text_lesson`, `pdf`, `mcq_assessment`, `video` | Presentation, audio, downloadable resource, recorded webinar, live session, feedback form (all listed in SRS `REQ-MOD-2`). |
| Video | One type `video` with two modes: YouTube/Vimeo iframe, or any HTTPS “Watch Video” link. Raw iframe HTML is rejected. Authors do not see separate **Video link**, **Video embed**, and **Video upload** choices. No in-page player for an uploaded file. | Upload + HTML5 player. Distinct link vs embed vs upload in the creator menu. |
| Text | Plain `body_text`, escaped, line breaks preserved. | Rich text / sanitised HTML. |
| PDF | `object_key` required at authoring. Learner sees a placeholder; no signed download. | Real file delivery (`PR-S3` still open). |
| Completion | `mark_complete` (text, PDF, video) or `assessment_passed` (MCQ). `content_progress` stores status, access times, `resume_position`, `watch_percentage`. Player records access (not started → in progress) but **does not write resume position or watch percentage**. | Configurable watch-% completion (`REQ-MOD-4`). Faculty manual mark-complete. |
| Release | Immediate vs sequential modules. Within a module, later items stay locked until prior **mandatory** items are completed. | Calendar release, N-days-after-enrolment, manual admin release (`REQ-MOD-3`). |
| Authoring | Course Admin curriculum form. Type dropdown includes Video on current branch. Labels still say Content type, object key, delivery mode. | Author-facing language (“Lesson”). Conditional fields (show video URL only when type is video). |
| Player | Outline (progress bar, locked badges, previous/next) and item page (iframe or external link, Mark complete, assessment start/continue). Outline shows raw `content_type`. | Resume CTA to last accessed item. Type-specific shells. |
| Assessment | MCQ linked to `mcq_assessment` content. Pass marks that item complete (`completion_source=assessment`) and may issue a completion certificate in the same transaction path. | Assessment opening/deadline notifications. |
| Certificate | `CertificateIssuanceService::issueCompletionIfEligible` after mark-complete or assessment pass, if every mandatory item is complete and a certificate name resolves. Outbox `certificate.issued`. | Participation certificates driven by watch/attendance data. |
| Notifications | Durable outbox + `notification:deliver` / `outbox:relay`. Transactional events today: application, payment, `enrolment.created`, `certificate.issued`. Email (or `local_file` on demo). | In-app inbox. Learning events (live session reminder, new module, assessment deadline) from `REQ-NOTIF-1`. |

**SRS gap (do not silently “fix”):** `REQ-MOD-2` already names types this codebase does not store. This roadmap sequences delivery. It does not remove those requirements.

**Not in the SRS (do not build until a Decision Log entry):** an in-LMS forum, and a separate learner–faculty question thread. Those are specified below as **proposals** so implementation has a shape when approved.

---

## 1. Content model evolution

Keep one stored activity per learner page. In the UI call it a **Lesson**. In the database it remains `content_items` until a later pack introduces grouped blocks.

### Required creator menu

When adding a lesson, the course creator selects one of these. Do not collapse video into a single “Video” option with a technical delivery radio. Quiz stays in the list because assessments already exist; it is not a substitute for the types below.

| Creator selects | Learner sees | Completion |
|---|---|---|
| Text | Plain reading | Mark complete |
| Rich text | Formatted reading (allow-listed HTML) | Mark complete |
| PDF | File open/download | Mark complete |
| Video link | Opens the URL in a new tab | Mark complete |
| Video embed | In-page YouTube or Vimeo player | Mark complete |
| Video upload | In-page player for the uploaded file | Mark complete |
| Podcast | Audio from a feed or episode URL | Mark complete |
| Audio upload | In-page player for the uploaded audio file | Mark complete |
| Live stream (link) | Date/time and Join (Google Meet, Zoom, or other HTTPS link) | Learner confirms attended |
| Quiz (existing) | Start / continue attempt | Assessment passed |

Live stream by link is required, not a later optional pack. Meet and Zoom are first-class. No Zoom/Meet API in the first build — the join URL is the feed.

**Why video feels wrong today:** embed and external link share one type and one form. Upload does not exist. The creator cannot choose “play here” vs “open link” vs “my file” as separate lesson kinds, and a non-YouTube URL cannot become an in-page file player.

Creator path to preserve:

`Course` → `Module` → `Lesson` (`ContentItem`)

Do not expose `content_id`, `object_key`, `video_delivery_mode`, or `video_provider` in author or learner copy. Persist them; hide them.

Completion stays on the existing pair of rules unless a Decision Log entry adds more:

| Rule | Meaning today | Use for |
|---|---|---|
| `mark_complete` | Learner presses Mark complete | Text, PDF, video, future audio/live “I attended” until watch-% or attendance APIs exist |
| `assessment_passed` | System sets complete on pass | MCQ only |

`content_progress.resume_position` and `watch_percentage` already exist. Do not add parallel progress tables. Write them only when a player can report a real position. Until then, “resume” means **open the last accessed incomplete lesson**, using `last_accessed_at`.

New types require: domain constant, migration `CHECK` + nullable typed columns, clone-on-version, authoring validation, player branch, completion allow-list in `MarkContentCompleteService`, HTTP tests. Locked versions cannot gain new lessons in place.

### Text

| | |
|---|---|
| **Now** | `content_type=text_lesson`. `body_text` required. Escaped plain text. |
| **Metadata** | Title (required). Body (required). Mandatory flag (existing). Estimated minutes (optional; not stored today — add only if the outline needs it). |
| **Learner** | Title, body, Mark complete, Previous/Next. |
| **Completion** | `mark_complete`. Opening the page records access only. |

### Rich text

| | |
|---|---|
| **Now** | Not supported. Do not treat newlines as rich text. |
| **Required** | Separate menu item from Text. Title + formatted body. |
| **First editor** | Restricted formatting only (paragraphs, lists, links, bold, italic). Prefer a small in-repo allow-list sanitiser. A third-party visual editor is not on the approved stack — stop and ask before adding one. |
| **Learner** | Render allow-listed HTML only. Links: `https` only, `rel="noopener noreferrer"`. |
| **Completion** | Same as text: `mark_complete`. Do not complete on scroll. |

### PDF

| | |
|---|---|
| **Now** | `content_type=pdf`. Author must enter `object_key`. Learner placeholder; no download. |
| **Metadata** | Title. Optional description (`body_text` is cleared on create today — restore an optional learner note when the download exists). Storage key (internal). Display filename (metadata only). |
| **Learner now** | Explain the file is not downloadable yet; still allow Mark complete if the course includes it (current demo behaviour). |
| **Learner when storage exists** | Short-lived signed URL (10–15 minutes). In-page viewer or download. Do not store the signed URL. |
| **Completion** | `mark_complete` until a “opened file” signal exists. SRS also allows “marked read”; that is the same learner action for this phase. |
| **Blocker** | Private object storage (`PR-S3`). Local disk is trial-only. |

### Video link

| | |
|---|---|
| **Now** | Buried inside `video` + `external_link`. Not a creator choice of its own. |
| **Required** | Separate lesson type in the menu. Title, optional description, HTTPS URL (any host: Drive, hospital portal, recorded webinar page). |
| **Learner** | Button opens the URL in a new tab (`rel="noopener noreferrer"`). No iframe. |
| **Completion** | `mark_complete`. The app cannot know the remote page was watched. |
| **Authoring rule** | Reject non-HTTPS. Do not accept raw iframe HTML here — that belongs in Video embed, and even there the creator pastes a watch URL, not HTML. |

### Video embed (YouTube / Vimeo)

| | |
|---|---|
| **Now** | `video` + `embedded`. Works only if the author finds the extra delivery radio. YouTube, YouTube no-cookie, and Vimeo watch URLs become an iframe via `SafeVideoEmbedBuilder`. |
| **Required** | Separate menu item **Video embed**. Fields: title, optional description, watch URL. |
| **Learner** | 16:9 in-page player. CSP `frame-src` stays limited to YouTube and Vimeo. |
| **Completion** | `mark_complete`. Do not claim watch-% — the iframe does not report progress. `watch_percentage` stays null. |
| **Authoring rule** | Accept `youtube.com/watch`, `youtu.be`, `youtube-nocookie.com`, `vimeo.com`. If the URL is not one of those, reject it and tell the creator to use **Video link** or **Video upload**. Never store pasted `<iframe>` HTML. |

### Video upload (in-page player for an academy file)

| | |
|---|---|
| **Now** | Not supported. Do not put `.mp4` files in `public/`. |
| **Required UX** | Separate menu item **Video upload**. Creator uploads a file. Learner gets an in-page HTML5 `<video controls>` player, not a download-only link and not a YouTube iframe. |
| **Metadata** | Title. Optional description. Private object key (not shown). Original filename (metadata only). MIME from file signature. Optional duration. Size cap to be set before build (do not invent a product limit here — propose 500 MB as a starting cap and confirm). |
| **Completion** | `mark_complete` first. When the HTML5 player can report position, write `resume_position` and `watch_percentage`. A “complete at N%” rule (`REQ-MOD-4`) waits for that signal and a product choice of N. |
| **Architecture conflict** | Technical Architecture v1.1 and AGENTS.md prefer **Mux or Cloudflare Stream** and say **do not self-host video**. “Locally hosted” must not mean a public file on the VPS or a transcode pipeline on the app server. Compliant shape: creator upload → private object → short-lived playback URL (10–15 min) → HTML5 player. Trial may use the same private local disk posture as documents (`APP_ENV` that allows local storage). Production cutover needs an explicit Decision Log choice: Mux/Stream **or** private object storage + HTML5 player. Do not silently ship public disk hosting. |

### Podcast

| | |
|---|---|
| **Now** | Not stored. Do not reuse Video link and pretend it is audio. |
| **Required** | Separate menu item. Title, optional description, HTTPS URL (episode page or direct audio URL). |
| **Learner** | If the URL is a direct audio file (`https` and an audio content type or known extension), in-page `<audio controls>`. Otherwise a “Listen” link in a new tab (Spotify, Apple, show page). Do not iframe those hosts. |
| **Completion** | `mark_complete`. |

### Audio upload

| | |
|---|---|
| **Now** | Not stored. |
| **Required** | Separate menu item. Creator uploads an audio file. Learner gets an in-page `<audio controls>` player. |
| **Metadata** | Title. Optional description. Private object key. Original filename. MIME from file signature. |
| **Storage** | Same private-object rule as video upload. Not `public/`. Trial local disk only when env allows it. |
| **Completion** | `mark_complete`. |

### Live stream (link) — required

| | |
|---|---|
| **Now** | Not stored. SRS and PRD already expect live sessions. |
| **Required** | Menu item **Live stream**. Google Meet and Zoom links must work. Other HTTPS join links (Teams, custom) are allowed as the same type. |
| **Metadata** | Title. Optional description. Join URL (HTTPS). Starts at / ends at (UTC in DB). Provider derived from host when possible: `google_meet`, `zoom`, otherwise `custom`. Optional label (“Week 3 clinic”). Nullable `external_meeting_id` for a later API — unused in the first build. |
| **Not in the first build** | Creating the meeting via Zoom/Meet API, OAuth, attendance webhooks. The link is the product. |
| **Learner** | Show date and time. **Join live class** opens the Meet/Zoom URL in a new tab. Before the start window, show the time and keep Join available (learners often join a few minutes early — do not hide the link until the exact start second). After the end time, keep the link for a configured grace (propose 2 hours; confirm) then show “Session ended”. Optional later field: recording URL (then it is a video link, not a second live type). |
| **Completion** | Learner confirms **I attended** (`mark_complete`, `completion_source=learner`). Do not mark complete because they clicked Join. Faculty override is a later Decision Log item. |
| **Notifications** | When mail workers exist for this event: outbox reminder before start (`REQ-NOTIF-1`). Idempotency: event + enrolment id + lesson id + start time. Not required to ship the join button. |

---

## 2. Course authoring UX

### Language

| Author sees | System |
|---|---|
| Course | `Course` + the version they are editing |
| Module | `Module` |
| Lesson | `ContentItem` |
| Lesson type | `content_type` + video delivery mode |

Never show “ContentItem”, “object key”, “delivery mode”, or “provider” in the form. Move storage keys to an advanced/internal field only when PDF upload exists; until then, do not ask authors to type S3 keys for a learner-facing demo.

If the version is locked, the only action is **Create version N+1**. Do not catch a DB trigger and show a raw exception.

### Flow

1. Course list → open version (draft).
2. Modules in order. Add / rename / reorder module.
3. Inside a module: **Add lesson**.
4. Pick a type in author language, then show only that type’s fields.

| Lesson type (label) | Fields shown | Stored as |
|---|---|---|
| Text | Title, plain text | `text_lesson` |
| Rich text | Title, formatted body | `text_lesson` + format flag, or a dedicated type if plain and rich must not share a column unchecked |
| PDF | Title, optional note, file | `pdf` |
| Video link | Title, optional description, URL | video family, mode `external_link` |
| Video embed | Title, optional description, YouTube/Vimeo URL | video family, mode `embedded` |
| Video upload | Title, optional description, file | video family, mode `upload` + private object key |
| Podcast | Title, optional description, URL | audio family, source `url` |
| Audio upload | Title, optional description, file | audio family, source `upload` |
| Live stream | Title, when, Meet/Zoom/other join URL | `live_session` |
| Quiz | Title, then existing question bank | `mcq_assessment` |

Video link, video embed, and video upload are three labels. They may share a `video` content type internally if the mode column distinguishes them, but the creator must never see one “Video” option. Live stream is its own type. Podcast and audio upload are not video.

**Video embed** accepts only a YouTube or Vimeo watch URL. **Video link** accepts other HTTPS URLs. **Video upload** accepts a file and plays it in the lesson player.

Quiz stays a lesson type so the outline order is one list. Do not make authors think the quiz lives outside the module. The question editor can stay on the following screen (current assessment config). Do not merge question CRUD into the lesson title form.

### Locked-version copy

“This published version cannot be edited. Create the next version to add lessons. Learners already enrolled stay on this version.”

---

## 3. Learner experience

### Layout

Keep the current two-level player. Do not introduce a third “lesson shell” in the next slice.

**Outline** (`/learning/enrolments/{id}`)

- Course title, enrolment reference, lifecycle.
- Progress: completed mandatory count / mandatory total (today the bar uses all items — prefer mandatory-only so optional lessons do not block the bar). Confirm against certificate eligibility before changing the numbers; certificate eligibility is mandatory items.
- Per module: title, locked badge if sequential rule fails.
- Per lesson: title, human type (Text, Video embed, Live stream, Quiz — never `text_lesson` or `video_delivery_mode`), status (Not started / In progress / Completed / Locked).
- Primary action: **Continue** → last `last_accessed_at` lesson that is accessible and not completed; else first accessible incomplete lesson.

**Lesson page**

- Back to outline, module name, title, status.
- Type-specific body (below).
- Completion control only when `PlayerAccessPolicy` and `ModuleReleasePolicy` allow it.
- Previous / Next among accessible items only (do not link a locked next item as if it were open).

### Progress

| Event | Write |
|---|---|
| Open lesson | `recordAccess` (exists): first/last accessed, not started → in progress. Idempotent. |
| Mark complete | `markCompleted` + audit `content_progress.completed` + certificate check (exists). |
| Assessment pass | same progress row, `completion_source=assessment` (exists). Fail does not complete. |
| Resume | Read `last_accessed_at`. Do not invent a second “bookmark” table. |
| Watch position | Leave null until a player reports it. |

### Type handling

| Type | On the lesson page |
|---|---|
| Text | Plain body. Mark complete. |
| Rich text | Sanitised HTML. Mark complete. |
| PDF | Open/download when the file exists. Mark complete. |
| Video link | Open URL in a new tab. Mark complete. |
| Video embed | YouTube/Vimeo iframe. Mark complete. No resume inside the iframe. |
| Video upload | HTML5 `<video controls>` from a short-lived URL. Mark complete. Resume position only after the player reports it. |
| Podcast | In-page audio if the URL is a direct file; otherwise Listen link. Mark complete. |
| Audio upload | HTML5 `<audio controls>`. Mark complete. |
| Live stream | Time, provider label (Meet/Zoom), Join. Then I attended. |
| Quiz | Start / Continue attempt. No Mark complete button. |

Opening a video does not complete it. That matches current code and avoids fake analytics.

---

## 4. Interaction layer (proposal — not approved)

**Not built.** This is not the support-ticket workflow and not a chat product. Do not start it in the next two days.

If approved, keep it asynchronous and on the existing notification pipe.

### Behaviour

- Learner, on a lesson, posts one question (text, length cap, CSRF, `learning.question.create_own`).
- Row: `question_id`, `enrolment_id`, `content_id`, `asked_by_user_id`, body, status `open` \| `answered` \| `closed`, timestamps.
- Faculty/Course Admin with batch or course scope replies once or in a short thread (still not live). No websockets.
- Learner sees status on the lesson and on the outline (“1 open question”) — not a global chat dock.

### Notifications

New outbox types, registered beside `TransactionalNotificationEventTypes`, worker-idempotent:

| Event | Recipient | Idempotency key |
|---|---|---|
| `learning.question.asked` | scoped faculty for that batch/course | question id |
| `learning.question.answered` | learner | question id + reply id |

Same rules as today: durable outbox, `notification:deliver`, no email inside the HTTP request, no `local_file` in staging/production.

Audit: ask and reply (actor, entity, no full body in logs if the body can contain clinical detail — store a hash or “body omitted”).

**Open product question:** who may answer — assigned faculty, any Course Admin on the course, or Credential Reviewer? Reviewers must not gain this by accident (segregation). Do not implement until that role is named in the Decision Log.

---

## 5. Forum integration (proposal — do not build a forum)

The LMS does not contain a forum and must not grow one (threads, moderation, search). SRS does not specify a vendor. Treat the following as integration requirements for a **future** Decision Log choice (Discourse, Circle, or similar). No vendor is selected here.

| Requirement | Rule |
|---|---|
| Identity | LMS is the IdP or issues a short-lived signed assertion. Forum does not store the LMS password. |
| Mapping | One forum user per LMS `user_id`. Email is a claim, not the primary key (emails change). |
| Groups | One group (or category) per **course** or per **batch** — product must pick one. Recommendation: **batch**, because faculty and live cohorts are batch-scoped; catalogue identity stays the course. |
| Membership | Add on Enrolment `Active`. Remove or archive on Withdrawn / Access expired / Cancelled. Idempotent sync job, not only a browser redirect. |
| SSO start | Authenticated learner hits `/learning/enrolments/{id}/community` → server checks enrolment access → redirects to the forum group URL with a one-time code. |
| No content sync | Questions, posts, and attachments stay in the forum. LMS stores `forum_group_key` on the batch or course version only. |
| Notifications | Forum may email its own users. Do not duplicate every forum post into `REQ-NOTIF-1` unless a specific event (for example “faculty replied”) is approved and mapped to an outbox type. |
| Finance | Forum links must not expose document or payment payloads. |

**Open product question:** vendor, batch vs course groups, and whether unpaid/admitted-but-not-active enrolments see the community. Default in this note: Active enrolment only.

---

## 6. Future compatibility

Add columns and interfaces so later packs do not rewrite lessons.

| Future | Shape now / next | Do not do |
|---|---|---|
| S3 / private video | Video upload and audio upload use a private object key and a short-lived playback URL into an HTML5 player. | Files in `public/`. Permanent URLs. Calling VPS disk “production video hosting”. |
| Zoom / Meet APIs | Live stream stores the join URL now. Nullable `external_meeting_id` for a later API. | Creating meetings from the LMS in the first build. Treating Join as attendance. |
| Analytics | Use `content_progress` timestamps and completion source. Optional later domain event `content_progress.accessed` on the outbox. | A second analytics schema in the 2-day slice. Watch-% without a player that can measure it. |
| Attendance | Live completion source stays explicit (learner confirm or faculty mark). API attendance maps into the same `content_progress` row. | Marking complete because the join URL returned HTTP 200. |

Course version clone (`CloneCourseVersionService`) must copy any new lesson columns. Publish validator must reject a video lesson with no URL and a live lesson with no join URL.

---

## 7. Implementation order

Required creator menu: Text, Rich text, PDF, Video link, Video embed, Video upload, Podcast, Audio upload, Live stream (link), plus existing Quiz.

### First build (do this before more UX polish)

1. **Split video in the creator form and the player.** Three labels. Video embed = YouTube/Vimeo iframe only. Video link = other HTTPS URL, new tab. Reuse current `video` columns for those two. Reject iframe HTML paste.
2. **Live stream (link).** New lesson type. Meet and Zoom URLs. Join button. I attended. No vendor API. Needs a migration (new type + start/end/join URL). Locked course versions still need Version N+1.
3. **Learner labels.** Outline must say Video embed / Video link / Live stream, not `video` or `external_link`. Continue button from last opened incomplete lesson.
4. **Tests** for embed iframe, external link, rejected raw iframe, Meet/Zoom join URL stored and rendered, attendance not implied by the join click.

### Same phase, storage decision required before coding the file path

5. **Video upload** and **Audio upload**: in-page `<video>` / `<audio>`. Private object, short-lived URL, not `public/`. Stop and record the Decision Log choice against Architecture “no self-hosting” before writing files to the VPS disk (see §Conflicts).
6. **Podcast** URL type (no upload). Can ship with the live-stream migration if the type enum is added together.
7. **Rich text** as its own menu item with an allow-listed sanitiser. No new editor library unless approved.
8. **PDF** file the learner can open — same private-object rule as upload. Until then, do not ask creators to type an object key.

### Not this phase

- Zoom/Meet API, attendance webhooks, watch-% completion, Q&A threads, forum SSO, drip release, analytics warehouse.

---

## Conflicts and questions (do not assume)

1. **SRS `REQ-MOD-2` vs code.** Launch list includes types not stored yet (audio, live session, and others). This note adds a creator menu that covers live stream, podcast, and audio upload. Presentation, downloadable resource, recorded webinar, and feedback form remain SRS items and are not in the creator menu above — recorded webinar is Video link or Video embed until a distinct type is justified.
2. **Video upload vs architecture.** AGENTS.md and Technical Architecture v1.1: Mux or Cloudflare Stream preferred; **no self-hosting**. The required learner experience is an in-page player for a file the creator uploaded. That is not a public file on the web server. Do not implement VPS-hosted video as the production design. Before the upload pack: Decision Log must choose trial private-disk playback and/or Mux/Stream vs private object storage + HTML5 player.
3. **Watch-% completion (`REQ-MOD-4`)** cannot be honest for YouTube/Vimeo iframes. First completion remains Mark complete. Uploaded HTML5 video may report position later.
4. **Faculty Q&A and forum SSO** are not specified in the SRS. Sections 4 and 5 are proposals. Stop and get a Decision Log entry before migrations or new permissions.
5. **Who answers a lesson question** is unspecified. Do not reuse Credential Reviewer.
6. **Progress bar denominator** (all items vs mandatory only) must match certificate eligibility before the UI copy says “course complete”.
7. **PDF object key in the author form** is a technical leak. PDF in the creator menu means a file upload, not a typed storage key.

---

*Documentation only. No application code, migrations, or configuration changes.*
