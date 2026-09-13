# Content media — implementation plan

**Status:** Plan only. Do not treat this file as implemented.  
**Date:** 2026-09-13  
**Decision:** [LX-VID-STORAGE-1](./DECISION_LOG.md)  
**Companion:** [LEARNING_EXPERIENCE_ROADMAP.md](./LEARNING_EXPERIENCE_ROADMAP.md)

**This pack builds the customer-facing lesson menu. It does not build video upload, audio upload, or an S3/Mux/Cloudflare Stream adapter.**

---

## 1. Scope

### In this pack

Creator adds a lesson as one of:

| Label | Stored as | Learner |
|---|---|---|
| Text | `text_lesson` | Escaped plain text. Mark complete. |
| Rich text | `rich_text` | Allow-listed HTML. Mark complete. |
| PDF | `pdf` | Open via short-lived signed URL. Mark complete. |
| Video embed | `video` + `embedded` | YouTube / Vimeo iframe. Mark complete. |
| Video link | `video` + `external_link` | New-tab HTTPS link. Mark complete. |
| Live session | `live_session` | Date/time, Join, optional recording link. Learner confirms attended. |
| Quiz | `mcq_assessment` | Unchanged. Pass completes the lesson. |

### Out of this pack

- Video upload, audio upload, podcast, HTML5 `<video>` / `<audio>` for academy files
- S3, Mux, Cloudflare Stream adapters
- Zoom / Google Meet APIs, OAuth, attendance webhooks
- Watch-percentage completion
- Faculty Q&A, forum SSO
- New WYSIWYG library (not on the approved stack)
- Application, Enrolment, or Payment state-machine changes

### Invariants

- Locked `CourseVersion` rows stay immutable. New lesson types are added on a Draft or Version N+1.
- Raw `<iframe>` HTML is never stored. Embed URLs are built by `SafeVideoEmbedBuilder`.
- No file is served from `public/`. Signed URLs expire in 10–15 minutes and are not persisted.
- `ObjectStorageFactory` still returns `UnconfiguredObjectStorage` unless `DOCUMENTS_STORAGE_DRIVER=local` and the environment allows local adapters. Do not add a fake public disk driver for production-like envs.
- Clone (`CloneCourseVersionService`) copies every new lesson column.
- Existing `video` rows remain valid.

---

## 2. Behaviour to implement

### Text and rich text

- Text: required `body_text`, stored and rendered escaped with line breaks (current behaviour).
- Rich text: required body, `body_format` unused if the type is `rich_text`. On save, run a domain sanitiser. Allow only `p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, `h2`, `h3`, and `a` with `https` href. Strip scripts, iframes, styles, event handlers, and other tags. Reject the save if nothing remains.
- No new npm editor. Admin field is a textarea. Optional jQuery buttons may insert the allowed tags; they are not a security control. The sanitiser is.

### Video embed and video link

Split the curriculum form into two labels. Keep one `content_type=video`.

| Label | Rule |
|---|---|
| Video embed | HTTPS watch URL only. Host must resolve to YouTube, YouTube no-cookie, or Vimeo via `SafeVideoEmbedBuilder`. Reject anything else and tell the author to use Video link. |
| Video link | Any other HTTPS URL. Provider `external`. Player does not iframe it. |

Reject non-HTTPS, control characters, and pasted iframe markup on both.

Learner page: embed uses the existing 16:9 iframe and CSP `frame-src` (`SecurityHeaderPolicy` already allows YouTube and Vimeo). Link uses a button, `target="_blank"`, `rel="noopener noreferrer"`. Opening either does not complete the lesson.

### Live session

Required fields: title, join URL, start time. End time optional. Recording URL optional.

- Join URL and recording URL must be HTTPS. No OAuth, no meeting creation.
- Provider is derived, not typed by the author: `meet.google.com` → `google_meet`; `zoom.us` / `*.zoom.us` → `zoom`; anything else HTTPS → `custom`.
- Learner page shows start (and end if set) in the presentation timezone, a **Join live class** button (new tab), and **Recording** only when a recording URL is stored. Recording is a link, not an embed.
- Join stays available. Do not hide it until the exact start second.
- Completion is **I attended** (`mark_complete`, `completion_source=learner`). Clicking Join does not complete the lesson.
- Reminder email is out of scope. Do not add an outbox event in this pack.

### PDF

Current learner page is a placeholder. Authors type an `object_key`.

This pack:

- Author uploads a PDF on the draft curriculum form (CSRF, course-admin permission, version not locked). Server checks the file signature (`%PDF`), not only the client MIME. Cap: **100 MB** (existing platform cap for downloadable resources). Random object key under a `learning/pdfs/` prefix. Original filename stored as metadata only.
- Persist via `ObjectStorage::putObject` (local driver already implements it). Do not write under `public/`.
- Learner with access to that enrolment and content item receives `issueDownloadUrl` (10–15 minutes). The player either opens that URL or redirects to it. The URL is not stored on `content_items`.
- Unsigned or expired local signed URLs stay 404 (`LocalStorageDownloadController` already works this way and is registered only for the local driver).
- If storage is `UnconfiguredObjectStorage`, save and download fail with a clear conflict message. Do not fall back to a public path.
- Credential-document Finance segregation is unchanged. Learning PDFs are course content, not `DocumentSubmission`. Do not reuse document-reviewer signed-URL methods. A learning download must check enrolment access (`PlayerAccessPolicy` + module release), not document permissions.

Remove the author-facing “object key” text field for new PDFs. Existing rows that only have a key still resolve through the same signed-URL path if the object exists.

### Future compatibility (schema and ports only)

- Leave `object_key` on `content_items`. Video upload will use it later. Do not add `upload` to the video delivery-mode check in this migration (that would allow a mode with no player).
- Do not add `ObjectStorage` methods that only Mux needs. A later adapter implements the existing port or a playback port beside it.
- Live session gets nullable `live_external_meeting_id` so an API pack does not rewrite the table. Leave it unused.
- Do not add audio columns.

---

## 3. Migrations

One Phinx migration, reversible `down()`:

`database/migrations/20260913000001_content_media_lesson_types.php`

| Change | Detail |
|---|---|
| `content_type` check | Allow `text_lesson`, `rich_text`, `pdf`, `mcq_assessment`, `video`, `live_session`. |
| Live columns, all nullable | `live_join_url` VARCHAR(2048), `live_starts_at` DATETIME, `live_ends_at` DATETIME NULL, `live_provider` VARCHAR(32), `live_recording_url` VARCHAR(2048), `live_external_meeting_id` VARCHAR(128). |
| Checks | `live_provider` null or `google_meet` \| `zoom` \| `custom`. Live type requires `live_join_url`, `live_starts_at`, `live_provider`. Non-live rows must have all live columns null. |
| Video checks | Keep existing: video requires URL + delivery mode + provider; non-video must not set video columns. Do not add `upload`. |
| PDF display name | `original_filename` VARCHAR(255) NULL. Used for the download `Content-Disposition` label. Not a public path. |
| `down()` | Delete `rich_text` and `live_session` rows (or refuse if product will not accept data loss — prefer delete only those types, same pattern as WP-L10 `down()` deleting `video`). Drop live columns and restore the previous type check. Do not drop video columns. |

No change to `content_progress`. Attendance uses existing `mark_complete`.

Do not migrate published versions in place. Authors clone to N+1 to add rich text or live sessions.

---

## 4. Files to change

### Domain

| File | Change |
|---|---|
| `src/Domain/Courses/ContentItemType.php` | Add `rich_text`, `live_session`. Creator labels stay in the template, not raw keys. |
| `src/Domain/Courses/ContentItem.php` | Live fields + original filename. |
| `src/Domain/Courses/ContentItemRepository.php` | Insert/update/hydrate contract for new columns. |
| `src/Domain/Courses/VideoDeliveryMode.php` | No `upload` value. |
| `src/Domain/Courses/SafeVideoEmbedBuilder.php` | Keep embed allow-list. Add a helper that rejects iframe markup before parse. |
| `src/Domain/Courses/LiveSessionProvider.php` | New. Derive provider from HTTPS host. |
| `src/Domain/Courses/RestrictedHtmlSanitiser.php` | New. Allow-list used on rich-text save. |
| `src/Domain/Courses/CourseVersionPublishValidator.php` | Live lesson missing join URL or start; video embed/link missing URL; rich text empty after sanitise; PDF missing object key. |
| `src/Domain/Courses/ContentCompletionRule.php` | `defaultForType`: live session and rich text → `mark_complete`. Quiz unchanged. |

### Application

| File | Change |
|---|---|
| `src/Application/Courses/ContentItemCommandService.php` | Branch create/update per type. Call sanitiser and live-URL policy. PDF upload writes via `ObjectStorage`. |
| `src/Application/Courses/CloneCourseVersionService.php` | Copy live columns and `original_filename`. Object key is copied (same private object; do not duplicate blobs in this pack). |
| `src/Application/Learning/LearnerPlayerQueryService.php` | Human type label data, embed vs link, live fields, PDF availability flag (not a stored URL). |
| `src/Application/Learning/LearnerPlayerItemDetailView.php` | New view fields for live session and PDF action. |
| `src/Application/Learning/MarkContentCompleteService.php` | Allow `rich_text` and `live_session` with `mark_complete`. |
| `src/Application/Learning/LearningPdfAccessService.php` | New. Enrolment + release check, then `issueDownloadUrl`. 404/403 if no access or object missing. Unconfigured storage → 409 with a clear message. |

### Infrastructure

| File | Change |
|---|---|
| `src/Infrastructure/Courses/PdoContentItemRepository.php` | Read/write new columns. |
| `src/Infrastructure/Storage/ObjectStorageFactory.php` | No new driver. Comment only if a learning prefix is configured beside documents. |
| `src/Infrastructure/Storage/LocalObjectStorage.php` | Reuse. PDF `Content-Type` on learning download should be `application/pdf` when the key is a learning PDF — set that in the learning controller, not by weakening the local document downloader. |

### HTTP, templates, routes

| File | Change |
|---|---|
| `config/container.php` | Register `GET /learning/enrolments/{enrolmentId}/items/{contentId}/pdf` on `LearnerPlayerController` or a small `LearningPdfController`. Same permission as the item page (`learning.content.access` + ownership). Wire `ObjectStorage` into the PDF service. |
| `src/Http/Controllers/CourseCurriculumController.php` | Accept PDF multipart on content create/update. Keep CSRF. |
| `src/Http/Controllers/LearnerPlayerController.php` | PDF redirect/open action. |
| `src/Http/Security/SecurityHeaderPolicy.php` | No new frame hosts. |
| `templates/pages/admin/courses/curriculum.php` | Seven lesson labels (plus quiz). Conditional fields. No object-key, provider, or “ContentItem” copy. |
| `templates/pages/learning/item.php` | Text, sanitised rich text, PDF open, embed iframe, external link, live join/recording/I attended. |
| `templates/pages/learning/outline.php` | Labels: Text, Rich text, PDF, Video embed, Video link, Live session, Quiz. Continue from `last_accessed_at` if that item is accessible and incomplete; else first accessible incomplete lesson. |

Do not change payment, admission, or document-review routes.

### Docs touched only if behaviour comments in code are insufficient

This plan and the Decision Log are the product record. Do not rewrite the SRS.

---

## 5. Tests required

| Layer | Cases |
|---|---|
| Unit | `SafeVideoEmbedBuilder`: YouTube, youtu.be, no-cookie, Vimeo pass; Drive/Meet/HTTP/`<iframe>` fail. `LiveSessionProvider`: meet.google.com, zoom.us, custom HTTPS; reject non-HTTPS. `RestrictedHtmlSanitiser`: keeps allow-list; strips `script`, `iframe`, `on*`. |
| HTTP authoring | Course Admin on a draft can create text, rich text, video embed, video link, live session. Video embed rejects a non-YouTube/Vimeo URL. Video link rejects HTTP. Live session requires join URL + start. Locked version returns conflict and does not write. |
| HTTP player | Learner item: embed HTML contains iframe `src` on youtube.com or player.vimeo.com only. Video link has no iframe. Live page has Join and does not complete on GET. POST complete sets completed. Recording link omitted when empty. Outline does not contain `text_lesson` or `external_link`. |
| PDF | Upload stores a private key, not a `public/` path. Learner with access gets a signed URL that expires. Learner without enrolment cannot. Expired/bad signature is 404. Unconfigured storage does not return a file. Finance document-denial tests stay green (this path is not a document URL). |
| Clone / publish | Clone copies live fields. Publish refuses a live lesson with no join URL and a video lesson with no URL. |
| Regression | `tests/Http/VideoContentHttpTest.php`, `tests/Unit/Domain/Courses/SafeVideoEmbedBuilderTest.php`, `tests/Http/LearnerPlayerHttpTest.php`, `tests/Http/CourseCurriculumHttpTest.php`. |

No new state-machine tests. No webhook tests.

---

## 6. Implementation sequence

Do these in order. Stop after each step if tests for that step fail.

1. **Domain + unit tests** — types, sanitiser, live URL policy, embed rejection of iframe HTML. No migration yet.
2. **Migration** — columns and checks. Fixture seeders that insert content items must include new nullable columns.
3. **Repository + command service + clone** — create/update/clone for text, rich text, video embed, video link, live session. PDF object-key path still works for existing rows.
4. **Admin template** — creator labels and conditional fields. Hide object key.
5. **Player query, item template, outline labels, mark complete** — embed, link, live join/recording, I attended, Continue.
6. **PDF upload + signed open** — `LearningPdfAccessService`, route, player button. Local driver only when env already allows it.
7. **Publish validator + HTTP tests** listed above.
8. **Do not** add Mux, S3, upload video, or audio in a follow-up commit on this pack.

---

## 7. Explicit non-goals (future hooks only)

| Future | What this pack leaves behind |
|---|---|
| Video upload | `object_key` column. No `upload` delivery mode. No `<video>` player. |
| Audio upload | No audio type, no columns. |
| S3 | `ObjectStorage` interface unchanged. Factory still unconfigured in production-like env. |
| Mux / Cloudflare Stream | No client, no env keys, no playback token. Decision Log must choose this before an adapter. |
| Meet / Zoom API | `live_external_meeting_id` nullable and unused. |

---

*Plan only. No application code or migration in this change.*
