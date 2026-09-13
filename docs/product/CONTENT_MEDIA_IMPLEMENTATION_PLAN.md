# Content media — implementation plan

**Status:** Plan only. Do not treat this file as implemented.  
**Date:** 2026-09-13 (corrected the same day: production pack, not an MVP deferral)  
**Decisions:** [LX-VID-STORAGE-1](./DECISION_LOG.md) (no public files, no transcode) · [LX-VID-STORAGE-2](./DECISION_LOG.md) (uploads included) · [LX-VID-STORAGE-3](./DECISION_LOG.md) (local **and** S3)  
**Companion:** [LEARNING_EXPERIENCE_ROADMAP.md](./LEARNING_EXPERIENCE_ROADMAP.md) — this plan wins if the roadmap still calls uploads “later”.

This is a **production** lesson-media pack. It does not add transcoding, adaptive streaming, HLS/DASH, or a video optimisation pipeline.

---

## 1. Scope

### Creator menu (all in this pack)

Keep `Course` → `CourseVersion` → `Module` → `ContentItem`. The UI says **Lesson**. Do not add a Lesson table.

| Label | Stored as | Learner |
|---|---|---|
| Text | `text_lesson` | Escaped plain text. Mark complete. |
| Rich text | `rich_text` | Allow-listed HTML. Mark complete. |
| PDF | `pdf` + private object | Open via short-lived signed URL. Mark complete. |
| Video embed | `video` + `embedded` | YouTube / Vimeo iframe. Mark complete. |
| Video link | `video` + `external_link` | New-tab HTTPS link. Mark complete. |
| Video upload | `video` + `upload` + private object | In-page `<video controls>` from a signed URL. Mark complete. |
| Podcast | `podcast` + HTTPS URL | In-page `<audio>` when the URL is a direct audio file; otherwise Listen. Mark complete. |
| Audio upload | `audio` + private object | In-page `<audio controls>` from a signed URL. Mark complete. |
| Live session | `live_session` | Start/end, Join (Meet, Zoom, Teams, or other HTTPS), optional recording link. Learner confirms attended. |
| Quiz | `mcq_assessment` | Unchanged. Pass completes the lesson. |

### Not in this pack

- Transcoding, ffmpeg, thumbnail generation, bitrate ladders, HLS, DASH, Mux, Cloudflare Stream
- Zoom / Meet / Teams APIs, OAuth, attendance webhooks
- Watch-percentage completion (`resume_position` / `watch_percentage` stay unused)
- Faculty Q&A, forum SSO, reminder emails (notification architecture unchanged; no new outbox event)
- A new WYSIWYG library
- Application, Enrolment, or Payment state-machine changes
- Editing a locked CourseVersion in place

### Invariants

- Completion stays `mark_complete` or `assessment_passed`. Live session uses Mark complete / “I attended”. Join does not complete the lesson.
- Locked versions stay immutable. New types are authored on a Draft or Version N+1.
- No media file is written under `public/` or returned as a permanent URL.
- Playback and download URLs are issued only after a permission check, expire in 10–15 minutes, and are not stored.
- Learning uploads support **both** backends: private local object storage and a private S3 bucket. The operator selects one. Neither is public. Local is not restricted to non-production environments.
- Credential-document storage is a separate setting. Choosing local for learning media must not store qualification documents on disk or expose them to Finance.
- Clone copies lesson columns and the object key. It does not duplicate the blob in this pack.

---

## 2. Database changes

One Phinx migration, reversible `down()`:

`database/migrations/20260913000001_content_media_lesson_types.php`

### Type check

Allow: `text_lesson`, `rich_text`, `pdf`, `mcq_assessment`, `video`, `podcast`, `audio`, `live_session`.

### Video

Extend the existing delivery-mode check to include `upload`.

| Mode | Required columns |
|---|---|
| `embedded` | `video_url`, `video_provider` in `youtube` \| `youtube_nocookie` \| `vimeo`. `object_key` null. |
| `external_link` | `video_url`, `video_provider=external`. `object_key` null. |
| `upload` | `object_key` set. `video_url` null. `video_provider` null. |

`down()` must not delete existing `embedded` / `external_link` rows. It may delete `upload` rows only if reverting (same pattern as WP-L10 deleting a type it introduced). Prefer refusing `down()` once production upload rows exist — document that in the migration comment and implement delete-of-new-types only, matching WP-L10.

### New columns on `content_items`

| Column | Type | Use |
|---|---|---|
| `original_filename` | VARCHAR(255) NULL | Download/player label only. Not a path. |
| `media_mime` | VARCHAR(128) NULL | Detected type, not the client claim. |
| `media_bytes` | BIGINT UNSIGNED NULL | Size at ingest. |
| `media_sha256` | CHAR(64) NULL | Integrity. |
| `podcast_url` | VARCHAR(2048) NULL | Podcast HTTPS URL. |
| `live_join_url` | VARCHAR(2048) NULL | Join link. |
| `live_starts_at` | DATETIME NULL | UTC. |
| `live_ends_at` | DATETIME NULL | UTC. Optional. |
| `live_provider` | VARCHAR(32) NULL | `google_meet` \| `zoom` \| `teams` \| `custom`. |
| `live_recording_url` | VARCHAR(2048) NULL | Optional HTTPS recording link (not an embed). |
| `live_external_meeting_id` | VARCHAR(128) NULL | Unused. Reserved for a later API pack. |

### Checks

- Podcast requires `podcast_url`. Other types must not set it.
- Audio requires `object_key` and `media_mime`. Must not set `podcast_url` or video columns.
- PDF requires `object_key`.
- Video upload requires `object_key` and forbids `video_url`.
- Live session requires `live_join_url`, `live_starts_at`, `live_provider`. Other types must have all live columns null.
- Non-video types must not set video URL / delivery mode / provider (existing rule, updated so `upload` is the exception that uses `object_key` instead of URL).

No change to `content_progress`, enrolment, or notification tables.

### Migration strategy

1. Additive columns and widened checks only. No table rename. No rewrite of locked version rows.
2. Existing `text_lesson`, `pdf`, `mcq_assessment`, and `video` (embed / external link) rows stay valid without a data backfill.
3. Deploy code that can read the new columns **with** the migration (same release). Old code must not run against the new check if it inserts video rows without the new columns — ship migration and code together.
4. Do not run `uat:reset` or `demo:prepare` as part of this migration.
5. `down()` drops new checks and columns after deleting only `rich_text`, `podcast`, `audio`, `live_session`, and `video` rows whose `video_delivery_mode=upload`. Leaves prior video and PDF rows.

**Open limit (do not invent in code):** platform cap for downloadable resources is 100 MB (`AGENTS.md` §10). Typical lecture video exceeds that. Confirm a video/audio upload cap before implementation. Until confirmed, the plan does not set a new number.

---

## 3. Storage changes

Reuse `Academy\Domain\Storage\ObjectStorage`. Do not add a second store for “videos”.

| Rule | Detail |
|---|---|
| Key | Random key, prefix `learning/media/`. Never the original filename. Never a `public/` path. |
| Write | Authorised course-admin request on an unlocked version. Sniff signature. Allow-list only types the browser can play **without** transcoding: PDF (`%PDF`), `video/mp4`, `video/webm`, `audio/mpeg`, `audio/mp4`, `audio/wav`. Reject anything else with a validation error that says the file must already be in a playable format. |
| Metadata | Persist filename, detected MIME, byte size, SHA-256 on the content item. Object body stays in the store. |
| Read | After enrolment + release check, `issueDownloadUrl` for 10–15 minutes. Learner page sets `<video src>` or `<audio src>` to that URL, or redirects for PDF. `Content-Disposition` uses `original_filename`. |
| Local backend | Supported for learning media in every environment when `LEARNING_STORAGE_DRIVER=local`. Private directory outside `public/`, signed GET, 10–15 minute expiry. Do not reuse the credential-document “local forbidden in production-like env” gate for this driver. |
| S3 backend | Supported when `LEARNING_STORAGE_DRIVER=s3`. Private bucket, no public ACL, short-lived GET. Same `ObjectStorage` port and same `learning/media/` key prefix. |
| Selection | One driver per deployment, from configuration. If the selected driver is missing credentials or the directory is not writable, fail that upload/play action. Do not fall through to `public/`. Do not change `DOCUMENTS_STORAGE_DRIVER` behaviour. |
| Credential documents | Learning keys use the `learning/media/` prefix. Do not issue learning URLs from document-review methods. Finance document denial tests must stay green. |

CSP: keep `frame-src` limited to YouTube and Vimeo. Uploaded media is same-origin or the storage host used in the signed URL. If the signed host is not `'self'`, add that host to `media-src` from configuration (the bucket host), not `*`.

---

## 4. Behaviour

### Text and rich text

Unchanged from the previous plan: plain text escaped; rich text saved through `RestrictedHtmlSanitiser` (`p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, `h2`, `h3`, `a` with `https` href). No new editor library. Textarea plus optional jQuery insert buttons. Sanitiser is the control.

### Video embed and video link

Unchanged: embed is YouTube/Vimeo watch URL only, iframe built by `SafeVideoEmbedBuilder`, raw iframe HTML rejected. Link is any other HTTPS URL, new tab, no iframe. Opening neither completes the lesson.

### Video upload

Creator uploads a file on the draft curriculum form. Learner with access gets an in-page player. No quality selector, no HLS manifest, no server-side optimisation. If the browser cannot play the file, that is a validation failure at upload, not a transcode job.

### Podcast and audio upload

| Label | Source | Player |
|---|---|---|
| Podcast | HTTPS URL | `<audio controls>` when the URL path ends in a playable audio extension (`mp3`, `m4a`, `wav`). Otherwise a Listen link. Do not iframe Spotify/Apple. |
| Audio upload | Private object | Always `<audio controls>` via signed URL. |

### Live session

Join URL required, start required, end optional, recording URL optional. All URLs HTTPS.

Provider derived from host:

| Host | Provider |
|---|---|
| `meet.google.com` | `google_meet` |
| `zoom.us`, `*.zoom.us` | `zoom` |
| `teams.microsoft.com`, `teams.live.com` | `teams` |
| Any other HTTPS | `custom` |

Learner sees the time range, **Join live class** (new tab), and **Recording** only if set (link, not embed). Join does not mark complete.

### Permissions

| Actor | Check |
|---|---|
| Course Admin upload / edit | Existing curriculum permission plus course/version scope. `CourseAdminAccessGuard` already calls `assertMutable`. |
| Learner play / download | `learning.content.access`, enrolment ownership (`PlayerAccessPolicy`), module/item release (`ModuleReleasePolicy`). |
| Other learner | 404. |
| Finance | No new document permission. Learning media is not `DocumentSubmission`. |

No new permission keys unless an existing key cannot express “edit curriculum”. Do not grant this to Finance or Credential Reviewer.

---

## 5. Affected files

### Domain

- `src/Domain/Courses/ContentItemType.php` — `rich_text`, `podcast`, `audio`, `live_session`
- `src/Domain/Courses/ContentItem.php` — new fields
- `src/Domain/Courses/ContentItemRepository.php` — contract
- `src/Domain/Courses/VideoDeliveryMode.php` — add `upload`
- `src/Domain/Courses/SafeVideoEmbedBuilder.php` — reject iframe markup; embed allow-list unchanged
- `src/Domain/Courses/LiveSessionProvider.php` — new (Meet, Zoom, Teams, custom)
- `src/Domain/Courses/RestrictedHtmlSanitiser.php` — new
- `src/Domain/Courses/LearningMediaPolicy.php` — new. Sniffed MIME allow-list, size check against the confirmed cap, key prefix
- `src/Domain/Courses/ContentCompletionRule.php` — defaults for new types
- `src/Domain/Courses/CourseVersionPublishValidator.php` — reject incomplete live / video / podcast / upload rows
- `src/Domain/Storage/ObjectStorage.php` — no new methods if `putObject` and `issueDownloadUrl` suffice

### Application

- `src/Application/Courses/ContentItemCommandService.php`
- `src/Application/Courses/CloneCourseVersionService.php`
- `src/Application/Learning/LearnerPlayerQueryService.php`
- `src/Application/Learning/LearnerPlayerItemDetailView.php`
- `src/Application/Learning/MarkContentCompleteService.php` — allow rich text, podcast, audio, video upload, live session when rule is `mark_complete`
- `src/Application/Learning/LearningMediaAccessService.php` — new. Permission + release, then signed URL. Used by PDF, video upload, and audio upload.

### Infrastructure

- `src/Infrastructure/Courses/PdoContentItemRepository.php`
- `src/Infrastructure/Storage/S3ObjectStorage.php` — new private-bucket adapter. No public ACL.
- `src/Infrastructure/Storage/LocalObjectStorage.php` — learning-media use must not be blocked by the credential-document production gate. Keep document uploads on the existing document driver.
- `src/Infrastructure/Storage/ObjectStorageFactory.php` — learning factory (or a mode argument) selects `local` or `s3` from `LEARNING_STORAGE_DRIVER`. Document factory stays as it is.
- `config/app.php` / `.env.example` — `LEARNING_STORAGE_DRIVER=local|s3`, local base path, S3 bucket, region, key prefix. No Mux keys.

### HTTP and templates

- `config/container.php` — `GET /learning/enrolments/{enrolmentId}/items/{contentId}/media` issues or redirects to the signed URL after access checks
- `src/Http/Controllers/CourseCurriculumController.php` — multipart upload on content create/update
- `src/Http/Controllers/LearnerPlayerController.php` — media action
- `src/Http/Security/SecurityHeaderPolicy.php` — `media-src` for the configured storage host only
- `templates/pages/admin/courses/curriculum.php` — ten labels, conditional fields, no object-key or “ContentItem” copy
- `templates/pages/learning/item.php` — players, join, recording, PDF open
- `templates/pages/learning/outline.php` — human labels; Continue from last accessible incomplete item

Do not change payment, admission, document-review, or notification worker event lists.

---

## 6. Test strategy

| Layer | Cases |
|---|---|
| Unit | Embed builder: YouTube/Vimeo pass; Drive, Meet, HTTP, raw iframe fail. Live provider: Meet, Zoom, Teams, custom; non-HTTPS fails. Sanitiser strips `script` / `iframe` / `on*`. Media policy rejects a non-allow-listed MIME and a key outside `learning/media/`. |
| HTTP authoring | Draft admin can create each in-scope type. Locked version does not write. Video embed rejects a Zoom URL. Video link rejects HTTP. Video upload rejects a non-mp4/webm payload and does not write a `public/` path. Audio upload and PDF same. |
| HTTP playback | Learner with access receives a signed URL (expiry ≤ 15 minutes) and an in-page `<video>` or `<audio>`. Learner without enrolment does not. Expired or bad signature is 404. Embed page iframes only YouTube/Vimeo. Video link has no iframe. Join GET does not complete the lesson; POST complete does. |
| Storage | With `LEARNING_STORAGE_DRIVER=local`, upload and signed playback succeed and the file is not under `public/`. With `s3`, the same tests use the private bucket adapter (or a fake S3 double that proves no public ACL and a short-lived URL). Missing config fails the action and does not write a public file. Finance document-denial tests still pass. Switching learning storage to local does not change document storage. |
| Clone / publish | Clone copies live and media columns. Publish refuses video upload with no object, podcast with no URL, live session with no join URL or start. |
| Regression | `VideoContentHttpTest`, `SafeVideoEmbedBuilderTest`, `LearnerPlayerHttpTest`, `CourseCurriculumHttpTest`. |

No state-machine tests. No webhook tests. No HLS fixture.

---

## 7. Implementation sequence

1. Confirm the video/audio byte cap (open question in §2). Do not guess it in the migration.
2. Domain types, sanitiser, live provider, media policy, unit tests.
3. Migration (additive). Update content-item fixtures for new nullable columns.
4. Repository, command service, clone — URL types first (text, rich text, embed, link, podcast, live session) so they do not depend on S3.
5. Admin and player templates for those URL types. Mark complete and outline labels.
6. Learning storage factory with **both** backends: private local and private S3. Document storage factory unchanged.
7. Upload + signed playback for PDF, video upload, and audio upload via `LearningMediaAccessService`.
8. Publish validator and the HTTP cases in §6.
9. Stop. Do not add ffmpeg, Mux, or a reminder outbox event in this pack.

---

## 8. Conflict recorded, not assumed

Technical Architecture v1.1 prefers Mux or Cloudflare Stream for protected adaptive video. This pack does not implement that. Learning uploads use private local storage **or** a private S3 bucket (`LX-VID-STORAGE-3`). Credential-document rules that forbid a local document driver in production-like environments are not changed. If learning local storage and that document rule are implemented as one shared flag, stop — they must be separate.

---

*Plan only. No application code or migration in this change.*
