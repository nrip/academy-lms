# WP-01 Decision Note — Prerequisites for Security, Identity and RBAC

**Purpose:** Options and recorded approvals for **WP-01A** and **WP-01B**.  
**Not implementation.** No code or migrations in this note.  
**Related:** [`VERTICAL_SLICE_ROADMAP.md`](./VERTICAL_SLICE_ROADMAP.md), [`DECISION_LOG.md`](./DECISION_LOG.md).

---

## Approval outcomes (2026-07-19)

| ID | Topic | Outcome |
| --- | --- | --- |
| WP01-A | Session + rate-limit store | **Approved:** MySQL for both for the initial production vertical slice, subject to load testing and review before horizontal scaling. Implementation requirements below. |
| WP01-B | Email verification | **Pending** (recommendation remains Amazon SES until recorded Approved in Decision Log). |
| WP01-C | Mobile verification | **Preferred but Pending:** SMS OTP. Production SMS **not** approved until provider, DLT, sender, templates, SLA, fallback and commercials are approved. Provider-neutral interface + fake/sandbox adapter allowed for development. Does **not** block WP-01A; **blocks WP-01B production completion**. |
| WP01-D | TOTP package | **Approved:** `spomky-labs/otphp`, subject to pinning, licence review, composer audit, encrypted secrets, hashed recovery codes, no secrets in logs, audited enrolment/reset/recovery. |
| WP01-E | Reviewer object scope | **Approved (amended):** Course, CourseVersion, and/or Batch assignments; union of active assignments; permission **and** scope required; no automatic future CourseVersions without explicit `include_future_versions`; effective dates, revocation history, creator/revoker, audit. |
| WP01-F | Hosting region | **Approved:** AWS `ap-south-1` as primary production-region **working assumption**, subject to final infrastructure, backup, logging, residency and DR approval. Region choice alone does **not** satisfy all compliance requirements. |

---

## Decision A — Shared session and rate-limit store

Closes Phase 0 Decision **D9** for initial slice build.

### Options considered

| Option | Summary |
| --- | --- |
| A1 | **MySQL** session table + MySQL-backed rate-limit counters |
| A2 | **Redis** for sessions + rate limits |
| A3 | MySQL sessions + Redis rate limits only |

### Approved: A1 — MySQL for both

**Decision text:** Approved for the initial production architecture, subject to load testing and review before horizontal scaling.

**Review triggers (must prompt re-evaluation, including possible move to Redis):**

- Database contention from session/rate-limit writes
- Transactional latency impact
- Session cleanup or lock contention across application nodes
- Failure to meet approved NFR/load-test thresholds

**Mandatory implementation requirements (WP-01A):**

- Separate **session** and **rate-limit** tables
- Indexed expiry columns
- Scheduled cleanup of expired rows
- **Hashed** session identifiers at rest
- **Atomic** rate-limit updates
- Documented failure behaviour (e.g. store unavailable → safe deny / fail closed for auth-sensitive actions; behaviour recorded in runbook/tests)

| Aspect | Detail |
| --- | --- |
| **Security** | Secure, HttpOnly, SameSite cookies; regenerate session ID on login/MFA; no passwords/OTPs in session or rate-limit payloads. |
| **Operational** | Single primary datastore; monitor purge job and table growth. |
| **Dependency / package** | In-repo PDO-backed adapters preferred; any Composer session package needs explicit approval. |
| **Cost / hosting** | No extra cache tier for initial slice. |

---

## Decision B — Email verification provider

### Options

| Option | Summary |
| --- | --- |
| B1 | **Amazon SES** |
| B2 | Transactional specialist (Postmark / Mailgun / SendGrid) |
| B3 | SMTP relay to academy mail |

### Recommendation: B1 — Amazon SES (still **Pending** formal Decision Log approval)

Unchanged rationale: AWS alignment, IAM secrets, `aws/aws-sdk-php` already named for S3/SQS. Required before WP-01B production email verification.

---

## Decision C — Mobile verification channel / provider

### Options

| Option | Summary |
| --- | --- |
| C1 | **SMS OTP** via India-capable gateway |
| C2 | WhatsApp OTP |
| C3 | Voice OTP fallback |
| C4 | Defer mobile verification — **not** REQ-REG-1 compliant |

### Outcome: Preferred but Pending (C1)

- Preferred channel: **SMS OTP**.
- Production SMS implementation is **not** approved until **all** of the following are approved: provider, DLT setup, sender identity, template approvals, SLA, fallback, and commercials.
- Development may use a **provider-neutral interface** and **fake/sandbox adapter**.
- **Does not block WP-01A.**
- **Blocks WP-01B production completion** (and thus production Application submit that requires verified mobile per REQ-REG-1).

---

## Decision D — TOTP package

### Approved: `spomky-labs/otphp`

Subject to:

- Compatible **version pinning** in `composer.json` / lockfile
- **Licence and dependency** review
- Passing **`composer audit`**
- **Encrypted** TOTP secrets at rest
- **Hashed**, single-use recovery codes
- **No** secret or provisioning URI in logs
- **Audited** enrolment, reset and recovery-code use

---

## Decision E — Reviewer object-scope schema

### Approved model (amended)

Assignments may target any of:

- **Course**
- **CourseVersion**
- **Batch**

**Effective scope** for an acting reviewer = **union** of all **active** assignments.

**Both** permission and object scope are required for document review access.

**Future versions:** A Course-level assignment must **not** automatically include future CourseVersions unless an explicit **`include_future_versions`** control is approved and enabled on that assignment.

**Assignment lifecycle fields (required):**

- Effective from / effective to (or equivalent)
- Revocation history (revoked_at, reason)
- Creator and revoker identity
- Audit events on create, amend, revoke

---

## Decision F — Hosting / India-region assumptions

### Approved: AWS `ap-south-1` working assumption

- Primary production-region **working assumption** for slice design.
- Subject to final approval of infrastructure, backup, logging, residency and disaster-recovery designs.
- **Do not** treat region selection alone as satisfying DPDP or other compliance obligations (NFR-SEC-2 and legal review remain).

---

## Gates

| Gate | Requirement |
| --- | --- |
| Start WP-01A | WP01-A and WP01-F recorded (done for A/F as above); implementation follows A requirements |
| Start WP-01B | WP-01A merged; WP01-D and WP01-E approved (done); WP01-B email provider Approved in Decision Log |
| Complete WP-01B for production | WP01-C production SMS pack approved **or** Product explicitly accepts non-production-only mobile verification path (not for production Application submit) |
