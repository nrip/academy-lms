# WP-02 — Course Detail, Batch Select and Draft Application

**Branch:** `slice/wp02-course-detail-draft-application`  
**Authority:** VERTICAL_SLICE_ROADMAP WP-02, APP-DRAFT-1, VS-SCOPE-1, SRS §4–5/§7–8/§17, Architecture §7.3.1/§8.2, Screen Inventory G-02 + A-03 (+ minimal G-01 catalogue for discovery).

## Scope choices (no Blocker)

| Topic | Decision |
|---|---|
| Draft Application | **In scope** per roadmap + APP-DRAFT-1 (entity factory, not SM transition). User brief’s “stop before Application” yields to authoritative roadmap ownership. |
| ApplicationStateMachine | **Not introduced** here (VS-SM-FULL-1 would require the full matrix). Repository has no `updateStatus()`. Submit remains WP-03. |
| Course Admin UI | Out of scope (VS-SCOPE-1). Synthetic published seed only. |
| Waitlist | Out of slice. Full/closed batches shown as unavailable. |
| Capacity reservation | **Not** at view/select. Hard capacity remains Architecture §8.2 (admission/enrolment). Selection refuses batches with status `full`/`cancelled`/outside window; seat counts are informational. |
| Catalogue listing | Minimal G-01-style `/courses` included so G-02 is reachable in demo (inventory G-01 not wireframed). |
| Public routes | Catalogue + course detail + batch list are public. Draft create requires `application.create` (active Applicant). |
| Mobile verification | Not required to view or create Draft. REQ-REG-1 applies at **submit** (WP-03). Draft owner page notes mobile verification before submit. |
| New permission keys | None. Reuse `application.create` / `application.view_own`. Public pages ungated. |
| Fee display | CourseVersion `standard_fee` + `gst_rate`; batch may override fee. Headline shows GST-inclusive amount. |

## Authoritative field list (implemented)

**courses:** `course_id`, `course_code`, `slug`, `master_title`, `status` (`active`\|`retired`), `current_published_version_id`, timestamps.

**course_versions:** `version_id`, `course_id`, `version_number`, `title`, `description`, `learning_objectives`, `intended_audience`, `syllabus_summary`, `admission_mode` (`A` only in seed), `delivery_type`, `duration_text`, `validity_period_days`, `standard_fee`, `gst_rate`, `currency`, `certificate_type`, `faq_json`, `status`, `published_at`, `locked_at`, `locked_reason`, timestamps.

**eligibility_rules:** `rule_id`, `course_version_id`, `field`, `operator`, `value`, `logic_group`, `display_label`, `sort_order`.

**course_document_requirements:** `requirement_id`, `course_version_id`, `document_name`, `description`, `mandatory_flag`, `accepted_file_types`, `max_size_bytes`, `single_or_multiple`, `reuse_allowed`, `reviewer_instructions`, `sort_order`.

**batches:** `batch_id`, `course_version_id`, `batch_code`, `name`, `starts_at`, `ends_at`, `applications_open_at`, `applications_close_at`, `min_capacity`, `max_capacity`, `delivery_mode`, `venue_or_online_details`, `timezone`, `fee_override`, `currency`, `status`, `access_expires_at`, timestamps.

**applications (Draft only):** `application_id`, `user_id`, `course_version_id`, `batch_id`, `status` (`draft`), `submitted_at` NULL, timestamps. Unique `(user_id, batch_id)` for idempotent Draft create.

## CourseVersion immutability

- Publish/seed sets `published_at`, `locked_at`, `locked_reason='published'`.
- Application create also locks an unlocked referenced version (`locked_reason='application_referenced'`).
- Domain services refuse mutation of locked versions/children.
- DB BEFORE UPDATE/DELETE triggers on `course_versions`, `eligibility_rules`, `course_document_requirements` reject changes when the version is locked (defence-in-depth).

## Batch availability (deterministic)

Selectable when all hold:

1. Parent course `status = active`
2. CourseVersion `status = published` and `locked_at IS NOT NULL`
3. Batch status = `open_for_applications`
4. `now` (UTC) ∈ `[applications_open_at, applications_close_at]`
5. Batch status is not `cancelled` / `archived` / `full` / `completed` / `planned` (planned = upcoming, not yet open)

No seat reservation write on select.

## Routes

| Method | Path | Access |
|---|---|---|
| GET | `/courses` | Public |
| GET | `/courses/{slug}` | Public (G-02) |
| GET | `/courses/{slug}/batches` | Public (A-03) |
| GET | `/batches/{batchId}` | Public summary |
| POST | `/applications` | `application.create` — body `batch_id` |
| GET | `/applications/{id}` | `application.view_own` — owner Draft read |

## Demo seed

Phinx seeder `Wp02DemoCatalogueSeeder` — local/testing/ci only; idempotent by stable `course_code` / `batch_code`. Never auto-run in production.

## Out of scope

Submit, documents, payments, reviewer UI, Course Admin builders, waitlist, enrolment, learning content, notifications beyond existing identity OTP.
