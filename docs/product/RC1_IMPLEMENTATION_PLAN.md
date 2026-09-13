# RC1 Production Academy Experience — Implementation Plan

**Status:** Implementation plan. Not an SRS amendment.  
**Date:** 2026-09-13  
**Baseline:** `main` after the product experience gap assessment.  
**Authority:** SRS v6.1 wins if this plan and the SRS disagree. Do not add states, payments, enrolments, or certificate rules. Do not build Q&A or a forum. Do not add a CMS.

Stored model stays `Course` → `CourseVersion` → `Module` → `ContentItem`. Customer language is Course, Chapter, Lesson, Quiz. Lesson-type labels already shipped in the player and curriculum form are not redone here.

---

## Slice boundaries

| Slice | In | Out |
|---|---|---|
| 1. Public academy | `GET /` storefront, catalogue cards, course detail polish, course cover image | Authoring terminology rewrite, learner dashboard, emails |
| 2. Learner experience | Dashboard cards, progress and continue, in-app notification inbox | Faculty inbox, reminder emails |
| 3. Admin and faculty | Course Admin counts, faculty dashboard, chapter language and publish explanation | Eligibility forms (slice 4), payment totals unless product assigns them to Finance |
| 4. Course configuration | Eligibility and required-document authoring on an unlocked edition | New document types or admission states |
| 5. Communication | Branded HTML around existing verification, application received, admission, and certificate mails | New notification events, template CMS |

Slices 2–5 are sequenced only. They are not implemented with slice 1.

---

## Slice 1 — what will be built

### Homepage

`GET /` is the guest front door. Brand mark links there. Copy uses language already in the PRD and the requested storefront brief:

- Headline: Learn from experts.
- Supporting line: structured programmes in obesity, metabolic health, and related clinical practice.
- Browse courses.
- Featured courses: the published catalogue. There is no featured flag. Do not invent a ranking or a cap that hides a published course.
- Why this academy: certificate programmes, verifiable certificates, built for doctors, nurses, and allied professionals. No invented partner names, learner counts, or CME credit numbers.
- Certificates: explain participation and completion certificates. Do not add a public number-search page; verification already exists at `/verify/certificates/{number}`.

No CMS. Copy lives in the template.

### Catalogue and detail

Existing public routes stay. Cards gain a cover area, duration, audience, fee, certificate type, and the next open intake name when a selectable batch exists. Course codes stay off the card. Detail keeps apply, batches, eligibility, and documents. It does not show version numbers, lock state, slugs, or storage keys.

### Course covers

Cover belongs on `courses`, not `course_versions`, so a published edition stays immutable. Replacing an image does not clone Version N+1.

- Columns: object key, display filename, MIME, byte size. No signed URL column.
- Prefix `learning/catalogue/`, separate from lesson files (`learning/media/`).
- Same private learning store (`LEARNING_STORAGE_DRIVER`). Not credential-document storage. Not `public/`.
- Public HTML uses `/courses/{slug}/cover`. The key never appears in HTML.
- That route returns the image only for an active published course. A draft cover is 404 to the public.
- Admins preview via `/admin/courses/{courseId}/cover` after scope check.
- Upload: Course Admin with `course.version.edit` and course scope. Lock on the edition does not block a cover change, because the cover is not edition configuration.
- Audit `course.cover_updated` with presence, MIME, and size. Do not write the object key into the audit payload.
- Cap: configuration `COURSE_COVER_MAX_BYTES`. Default **5,242,880** (5 MB), the approved profile-image platform cap. No course-cover cap was specified; this reuses that existing image cap rather than inventing a new one. JPG, PNG, or WebP, sniffed from bytes.

---

## Affected files (slice 1)

- `docs/product/RC1_IMPLEMENTATION_PLAN.md`
- `database/migrations/20260913000002_course_cover_images.php`
- `src/Domain/Courses/Course.php`, `CourseRepository.php`, `CourseCoverPolicy.php`
- `src/Infrastructure/Courses/PdoCourseRepository.php`
- `src/Infrastructure/Storage/LearningLocalObjectStorage.php` (allow the catalogue prefix; still private, still not `public/`)
- `src/Infrastructure/Storage/CourseCoverStorage.php`
- `src/Application/Courses/CourseCoverService.php`, `CatalogueService.php`
- `src/Http/Controllers/CourseCatalogueController.php`, `CourseAdminController.php`
- `config/container.php`, `config/security.php`, `.env.example`
- `templates/pages/home.php`, `templates/pages/courses/index.php`, `show.php`, `_card.php`
- `templates/pages/admin/courses/show.php`, `templates/layouts/base.php`
- `public/assets/css/acad-app.css`
- `src/Domain/Audit/CoursesAuditPayload.php` (allow-list fields only)
- Tests under `tests/Http/` and `tests/Unit/Domain/Courses/`

---

## Database

One additive migration. `courses` gains nullable cover columns. `down()` drops them. No new permission. No state column. CourseVersion triggers are not touched.

---

## Testing

- Homepage returns the storefront and a published course title, and does not echo a storage key.
- Catalogue shows an open intake name when a selectable batch exists.
- Public cover is 404 without an image and 404 for an unpublished course even if a file was stored.
- After an in-scope Course Admin uploads a PNG, the public cover route returns that image type and the catalogue HTML does not contain the object key.
- Policy rejects a non-image and an oversize file.
- Existing catalogue and apply routes keep their URLs.

---

## Dependencies between slices

```text
Slice 1 (public + covers)
    |
    +--> Slice 2 reuses the cover URL and catalogue title on learner cards
    |
    +--> Slice 3 does not depend on covers, but admin cover upload is already on the course page
    |
    +--> Slice 4 fills eligibility and documents that the public detail page already renders
    |
    Slice 5 is independent (email templates). It can follow slice 1 without waiting for 2–4.
```

Slice 2 must not invent a second progress formula. Slice 3 must not put payment line items on Course Admin. Slice 4 must refuse edits when the edition is locked. Slice 5 must not add events.

---

## Assumptions recorded for slice 1

- Featured courses means every published course, not a manually curated set.
- 5 MB is the cover cap because that is the approved profile-image cap. Override with `COURSE_COVER_MAX_BYTES` if product sets a different number.
- A 16:9 crop in CSS is presentation only. It is not a stored requirement.
- Cover changes do not require Version N+1.

---

*Slice 1 is the only slice this document authorises for immediate implementation.*
