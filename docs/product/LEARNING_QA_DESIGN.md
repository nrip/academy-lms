# Learning Q&A — RC2 Slice 1 design

**Status:** Approved for implementation (`LX-QA-1`…`LX-QA-4`).  
**Date:** 2026-09-15  
**Slice:** RC2 Slice 1 — Learning Q&A  
**Authority:** SRS v6.1 wins on conflict. Support tickets (`REQ-SUP-*`) stay a separate helpdesk product. A forum stays out of this repository.  
**Decision Log:** `LX-QA-1`, `LX-QA-2`, `LX-QA-3`, `LX-QA-4`  
**Related:** [`LEARNING_EXPERIENCE_ROADMAP.md`](./LEARNING_EXPERIENCE_ROADMAP.md) §4, [`PRODUCT_EXPERIENCE_GAP_ASSESSMENT.md`](./PRODUCT_EXPERIENCE_GAP_ASSESSMENT.md) §3.7, [`DECISION_LOG.md`](./DECISION_LOG.md)

---

## What this is and is not

| This slice | Not this slice |
|---|---|
| One learner asks a text question about a lesson they can open | Real-time chat, websockets, typing indicators |
| Course-scoped faculty posts one or more **responses** | Peer discussion, likes, or a course wall |
| Durable outbox email + learner inbox for “responded”, same pipe as RC1 | A second messaging system or SMS |
| Existing session, MFA, and roles | A new authentication or Faculty role table |
| Private thread: asker and scoped faculty only | A forum, Discourse, or community SSO |
| Lesson-anchored academic clarification | Support ticket (`REQ-SUP-*`), refund, payment, or document help |
| Attachments, reactions, learner replies | Out of slice |

Customer language: **Ask a question** / **Response** (not “Answer”). Do not say ContentItem, outbox, or CourseVersion in the UI. Stored model stays Course → CourseVersion → Module → ContentItem. Every question row stores lesson and course context (`content_id`, `course_id`, `batch_id`, enrolment).

---

## Existing anchors (do not redesign)

| Surface | Today | Q&A uses |
|---|---|---|
| Lesson page | `/learning/enrolments/{id}/items/{contentId}` | Ask form and the learner’s own thread under the lesson |
| Outline | `/learning/enrolments/{id}` | Optional count of open questions for this enrolment |
| Content access | `PlayerAccessPolicy`: Active enrolment to open a lesson; Scheduled may see outline only | Ask only when the learner can already open that lesson |
| Faculty home | `/faculty` via `course.view_assigned` + course-admin scope. No Faculty role | Open-question queue for courses in scope |
| Course Admin | Same assignment as Faculty | May respond when they hold course scope |
| Notifications | Domain outbox → `notification:deliver` → email; inbox row after a successful send | Two new transactional event types on that pipe |
| Support | Spec only (`REQ-SUP-*`). Not built | Remains separate |

---

## 1. Learner question flow

Preconditions: authenticated; `learning.content.access`; enrolment **Active** and owned; lesson already openable under `PlayerAccessPolicy` and module release.

1. On the lesson page, **Ask a question** (plain text, max 2 000 characters). No attachments.
2. CSRF submit. Persist `learning_questions` with status `open`, including course/lesson/batch context.
3. Same transaction: audit (no full body) + one outbox `learning.question.asked` **per** scoped Course Admin recipient.
4. Redirect to the lesson. Status chip: **Waiting for a response**.
5. HTTP does not send mail.

Rules: learner cannot edit or reply; may **close** an open question; sees only own questions; quiz attempt screens do not host Ask.

---

## 2. Faculty response flow

No Faculty role. Responding uses `learning.question.respond` plus course-admin scope (`LX-QA-2`).

1. Faculty home **Questions** list (open first), or `/faculty/questions/{questionId}`.
2. Context always shown: course title, chapter title, lesson title, learner display name, asked-at (India time for display).
3. Post a **Response** (plain text, max 2 000). First response → status `answered`. Further faculty responses stay allowed (`LX-QA-3`).
4. Audit + outbox `learning.question.responded` to the learner.
5. Optional **Close** without a new response (no mail in this slice).

Credential Reviewer and Finance cannot view or respond.

---

## 3. Data model

### `learning_questions`

`question_id`, `enrolment_id`, `content_id`, `course_id`, `course_version_id`, `batch_id`, `module_id`, `asked_by_user_id`, `body`, `status` (`open`|`answered`|`closed`), `asked_at`, `first_responded_at`, `closed_at`, `closed_by_user_id`, timestamps.

Indexes: `(course_id, status, asked_at)`, `(enrolment_id, content_id, asked_at)`, `(asked_by_user_id, asked_at)`.

Lesson/course context columns are required so faculty and mail can show titles without joining away the academic place of the question.

### `learning_question_responses`

`response_id`, `question_id`, `responded_by_user_id`, `body`, `responded_at`, `created_at`.

**No** unique constraint on `question_id` — multiple faculty responses are allowed. Learners never insert into this table.

### Status

```
open → answered   (first faculty response)
open → closed
answered → closed
closed → []
```

No reopen.

---

## 4. Permissions

| Permission | Applicant | Course Admin | Reviewer | Finance | Super Admin |
|---|---|---|---|---|---|
| `learning.question.create_own` | Yes | No* | No | No | No* |
| `learning.question.view_own` | Yes | No* | No | No | No* |
| `learning.question.respond` | No | Yes | No | No | Yes** |
| `learning.question.view_scoped` | No | Yes | No | No | Yes** |

\* Dual-role users use Applicant permissions only for their own enrolments.  
\** Still needs course scope.

---

## 5. Notification integration

| Event | Recipients | Subject | Inbox |
|---|---|---|---|
| `learning.question.asked` | Each Course Admin with scope on that course | “A learner asked a question” | No |
| `learning.question.responded` | Asking learner | “Your question has a response” | Yes, after successful send |

Email prefers deep link + course/lesson titles; **not** the full question body (`LX-QA-4`). Idempotency: `learning.question.asked:{question_id}:{recipient_user_id}` and `learning.question.responded:{question_id}:{response_id}`.

---

## 6–8. UI summary

- **Learner:** thread under the lesson; Waiting / Responded / Closed; ask form; no peer questions.
- **Faculty:** queue on `/faculty`; detail with always-visible course + lesson context; response form while not closed.
- **Admin:** Course Admin = faculty actor. Reviewer/Finance see nothing.

---

## 9. Security

Ownership + Active access for ask/view own. Course scope for respond/view scoped. Escape plain text. CSRF. Rate limit asks. Audit omits body (ids and status only). No document or payment data on Q&A screens.

---

## 10. Forum compatibility

Keep `batch_id` on questions. Do not sync Q&A into a forum. Future community SSO remains a separate Decision Log item.

---

## Out of scope

Chat, forum, learner-to-learner discussion, attachments, reactions, faculty in-app inbox, support tickets, state-machine changes for enrolment/payment/CourseVersion.
