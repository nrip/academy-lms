# Demo mode decision

**ID:** `DEMO-MODE-1`  
**Date:** 2026-09-14  
**Status:** Approved as playground policy. Not implemented. Not an SRS amendment.  
**Approver:** Product Owner (Nrip Nihalani)  
**Related:** [`DECISION_LOG.md`](./DECISION_LOG.md), [`../deployment/ACADEMY_DEMO_PLAYGROUND_PLAN.md`](../deployment/ACADEMY_DEMO_PLAYGROUND_PLAN.md), [`../deployment/RC1_DEMO_ENVIRONMENT_PLAN.md`](../deployment/RC1_DEMO_ENVIRONMENT_PLAN.md)

This note decides how the approved Academy Demo Playground behaves. It does not authorise a build.

## What is approved

| Keep | Do not add |
|---|---|
| One Hetzner host | A second sandbox or demo machine |
| One academy, one catalogue, one branding | A tenant, organisation workspace, or private catalogue |
| Course → CourseVersion → Module → ContentItem | A new content model. Customer language stays Course, Edition, Chapter, Lesson, Quiz |
| Existing roles | A Faculty, Operator, or Demo role |
| Facilitated demos on seeded showcase data | Editing or replacing that showcase during a prospect's self-service session |
| Self-service creation of demo content | A prospect-owned academy |

Both uses share the host. A guest can browse the seeded showcase immediately. A prospect who registers can later create courses. A facilitated demo still uses the seeded personas and the showcase script.

---

## 1. Demo registration flow

Registration on the playground uses the existing Register flow. No second signup form and no "create your academy" product.

1. Guest opens the public storefront and can browse published showcase courses with no account.
2. Register collects the same fields as today: email, mobile, password, terms, privacy. It creates an **Applicant** only.
3. The account is not usable for authoring until email verification succeeds (§3).
4. After verification, on this host only, Course Admin is added (§2). The person still has Applicant, so they can also apply to a showcase course.
5. They sign in again. Course Admin routes stay behind the existing MFA enrolment until they finish it.
6. They create a course from Course Admin. The draft is absent from the public catalogue until they publish an edition. Publish uses the existing lock rule.

The facilitated demo does not run this flow. The room uses seeded personas. Those passwords and emails are not printed on the public site.

Register stays rate-limited (`auth.registration`, 10 per hour per IP, fail closed). SMS verification stays off. `WP01-C` is still pending; this note does not approve production or demo SMS.

---

## 2. How demo users receive Course Admin capability

A prospect must be able to create demo content without a facilitator assigning a role, and without a new role.

**Decision:** when email verification succeeds, and only when the demo-host gate in §5 is on, the application assigns the existing `course_admin` role in addition to `applicant`.

| Rule | Why |
|---|---|
| Use `RoleAssignmentService` | Existing audited assign path. It bumps `auth_version` and revokes sessions, so the new role is not silent on the old session. |
| Reason on the audit row | Distinguish demo-host grant from a human admin assignment. |
| Do not grant Reviewer, Finance, or Super Admin | Segregation of duties. Those journeys stay on seeded personas. |
| Do not assign scope on showcase courses | Creating a course already scopes that course, including future editions, to the creator. That is the only scope they receive. |
| MFA stays mandatory | Course Admin cannot open Course Admin or Faculty home until TOTP enrolment completes. Do not disable MFA for the playground. |
| The grant does not run for seeded users | It runs on verification of a Register account, not on `demo:prepare` or `uat:seed`. |

There is no self-service way to become a reviewer or a finance user. A prospect who wants to see those desks watches the seeded personas in a facilitated session.

---

## 3. How demo email verification works

Self-service registration cannot finish if the letter exists only as a file on the server.

**Decision:** verification uses the existing letter and the existing token route.

| Item | Decision |
|---|---|
| Subject | "Verify your Academy account" |
| Delivery | To the email address entered at Register, so the prospect can open it without SSH |
| Token | Carried in the link, as the current route requires. Visible HTML must not print the token as letter text. The letter is not copied to the learner inbox. |
| Sender | A demo-only From address on this host. Not the production academy mailbox, not the production SES identity, not a per-prospect domain. |
| After verify | Course Admin grant (§2), then MFA enrolment, then authoring. |

The other three branded letters (application received, admission, certificate ready) use the same demo sender when those events happen on this host. They are not a second mail system.

`demo:prepare` today refuses any adapter other than `local_file` or `recording`. That gate was written for a facilitator-only machine. A later implementation must let this host send to the registrant and still refuse production mail credentials. Production continues to reject `local_file` and `recording`. This note does not change that production rule.

Showcase letters for a facilitated demo may still be captured on disk if that is how a run is prepared. That does not replace inbox delivery for a prospect who registered themselves.

---

## 4. Demo-only restrictions

These apply on the playground host. They are not production product rules.

| Restriction | Bound |
|---|---|
| Payment | Fake gateway only. Button remains **Complete demo payment**. The next screen is **Confirming payment…**. The browser return is not confirmation. No Razorpay keys on this host. |
| Documents | Local storage and the fake scanner. Not production object storage. Credential documents stay separate from learning media. Finance still cannot read them. |
| Roles an external user can hold | Applicant and Course Admin only. |
| Showcase | External users are not scoped to seeded courses. They cannot edit a published showcase edition. |
| Catalogue | One list. A published demo course appears beside the showcase. There is no hide flag and no private catalogue. Facilitated demos open showcase courses by their known URLs if the list also contains visitor courses. |
| Course count | At most **3** courses created by one demo user, including drafts. Enough to try create, next edition, and publish. Not a product-wide cap. |
| Authoring | Unlocked editions only. Publish locks the edition. Next edition is the change path. No new states. |
| Fees | A new course still starts at the existing draft fee (`0.00`) until the operator sets it. This host does not take a real charge either way. |
| Eligibility | Configuring categories and notes is allowed. Apply-time blocking by profession is still not a product behaviour. Do not add it for the playground. |
| Progress and certificates | Unchanged. The progress bar is not "course complete" and is not certificate eligibility. |

Seeded Course Admin, Reviewer, Finance, and Super Admin accounts remain for facilitated demos. They are not offered as public logins.

---

## 5. Production safety boundaries

The playground is a different machine and a different database from any customer production host. Same application. Different `.env`.

Demo-only behaviour (Course Admin grant after verification, demo sender, fake payment, fake scanner, seed and reset) runs only when **all** of the following hold:

- `APP_ENV` is `uat`
- an explicit demo-host switch is on (name it in implementation; it must default off)
- the process is not staging and not production

If the switch is on and `APP_ENV` is `staging` or `production`, the process must refuse to boot that configuration. A customer UAT that uses `APP_ENV=uat` must not inherit the grant unless that switch is deliberately set. The switch is forbidden on a customer or production host.

Production boundaries that do not change:

| Boundary | Rule |
|---|---|
| Registration | Creates Applicant only. Never Course Admin. |
| Mail | Production adapter selection and production From address. No demo sender. |
| Payments | Server webhook is confirmation. No fake gateway. |
| Documents | Production storage and scanning. Finance still cannot read credential documents. |
| Seed and reset | `demo:prepare`, `uat:seed`, and `uat:reset` stay refused on staging and production. |
| Data | No production dump, live Razorpay key, or learner PII on the playground. No playground database copied to production. |
| State machines | Unchanged. Enrolment still exists only when Application status is Admitted. |
| Editions | A published or otherwise locked CourseVersion stays immutable. |
| Architecture | No tenant column, no second catalogue, no forked application. |

---

## 6. Storage and quota

No new quota tables and no per-tenant bucket.

**Per-file caps that stay at the platform values**

| Kind | Cap |
|---|---|
| Credential document | 10 MB |
| Course cover | 5 MB |
| Profile image | 5 MB |
| Support attachment | 10 MB, 5 files |

**Learning media on this host only.** `LX-LEARN-POLISH-1` remains the production default (PDF 100 MB, audio 100 MB, video 500 MB). The playground `.env` sets the existing variables lower:

| Variable | Playground value |
|---|---|
| `LEARNING_MEDIA_PDF_MAX_BYTES` | 25 MB |
| `LEARNING_MEDIA_AUDIO_MAX_BYTES` | 25 MB |
| `LEARNING_MEDIA_VIDEO_MAX_BYTES` | 50 MB |

PHP `upload_max_filesize` and `post_max_size` on this host must accept the playground video cap plus multipart overhead. They must also stay at or above the 10 MB credential cap that `demo:prepare` checks. Do not raise production caps to match this host, and do not lower production caps to match it.

There is no per-user byte ledger in this decision. The server disk is the aggregate ceiling. If free space is low, stop pointing prospects at Register. A byte quota would be a new control and is not approved here.

Documents and learning files on this host use local private storage. Do not attach the production bucket. Signed URLs stay short-lived. Nothing is written under `public/`.

---

## 7. Reset and archive strategy

Two cleanups. They must not be one command.

### Showcase

`uat:reset --confirm` then `demo:prepare --confirm` rebuilds seeded personas and showcase courses. It deletes `@uat.example.test` users and `UAT-` applications. It does not delete people who registered themselves, or courses they created.

Use it before a formal guided demo if the showcase was damaged. It also drops MFA on the seeded personas; enrol that again before the room. Do not use it to remove a prospect.

### Prospect content

No archive status is added to Course, CourseVersion, Application, or User. Do not hand-edit statuses.

A published or otherwise locked edition cannot be deleted. Triggers already reject that change. This note does not weaken that rule to make cleanup easier. A published demo course stays on the shared catalogue.

Unlocked draft courses with no applications may be removed later by a demo-only cleanup. That cleanup is not part of this approval. When it is specified, it must:

- refuse to run unless the demo-host gate in §5 is on
- refuse to delete showcase course codes and `@uat.example.test` users
- refuse to delete a locked edition or any edition an application references
- write an audit record for each removal

Until that cleanup exists, prospect drafts and published demo courses remain. Disk and the 3-course cap are the limits. A bad published course is an incident: do not clear `locked_at` or change status in the database to hide it.

Captured mail files that contain verification tokens are deleted after a facilitated session once the letters still needed for the next showcase run have been regenerated. Inbox rows are not a mail archive and are not exported.

No retention period in days is set. Auto-delete of locked content is not approved.

---

## Explicitly not decided by inventing product behaviour

- Apply-time eligibility evaluation
- Merging the admission letter and the enrolment-created letter
- A public featured-course ranking
- A hide-from-catalogue flag
- Revenue on Course Admin
- Production SMS
- A per-user byte quota or an archive state

---

## Implementation gate

Do not implement from this note until asked. A later build must stay inside this decision: environment gate, existing role and scope, existing verification letter, existing file caps via configuration, and no change to production registration or state machines.
