# Email / notification architecture review — Academy LMS

**Scope:** Phase 1 post-functionality review (admissions, Razorpay payments, learning, certificates).  
**Date:** 2026-09-06  
**Mode:** Read-only architecture inventory — **no application code changes** in this document’s producing work.

This note answers: how notifications work today, which emails exist, and what is required to replace demo/local delivery with a production provider.

---

## 1. Existing notification flow

### 1.1 Dual-path design (shared outbox)

| Path | Worker | Durable delivery state | Channel |
|---|---|---|---|
| Identity (OTP / tokens) | `IdentityNotificationDeliveryWorker` | `verification_tokens.delivery_status`, `verification_challenges.delivery_status` | Email or SMS |
| Mode A transactional (WP-07) | `TransactionalNotificationDeliveryWorker` | `notification_deliveries` | Email only |

Both paths claim rows from the same transactional outbox table (`outbox_messages`).  
`OutboxRelayService` publishes **other** outbox events to `OutboxTransport` and **excludes** identity + transactional notification event-type lists so those events are not double-handled via SQS/memory relay.

CLI entrypoint:

```bash
php bin/jobs.php notification:deliver
```

Runs identity delivery first, then transactional delivery (`bin/jobs.php`). Cadence documented in `docs/operations/WORKERS_AND_SCHEDULES.md`.

### 1.2 Where events are generated

Domain / application services enqueue via `OutboxWriter::enqueue(...)` (`src/Domain/Outbox/OutboxWriter.php`), implemented by `PdoOutboxRepository` (status `pending`, unique `idempotency_key`).

**Identity (sealed secret + outbox):**

| Writer | Event type constant | Typical trigger |
|---|---|---|
| `VerificationTokenIssuer` | `identity.email_verification.send` | Registration / email resend |
| `VerificationTokenIssuer` | `identity.password_reset.send` | Forgot password |
| `VerificationChallengeIssuer` | `identity.mobile_otp.send` | Mobile OTP / resend |

**Application / admissions:**

| Writer | Event type |
|---|---|
| `ApplicationSubmitService` | `application.submitted` |
| `ApplicationCorrectionRequestService` | `application.correction_requested` |
| `LearnerCorrectionResubmitService` | `application.corrections_resubmitted` |
| `ApplicationDecisionService` | `application.approved`, `application.rejected` |
| `SuccessfulPaymentAcceptanceService` | `application.admitted` |

**Payment / enrolment (subset drive email):**

| Writer | Event type | Email? |
|---|---|---|
| `PaymentCheckoutService` | `payment.attempt_created`, `payment.gateway_order_bound`, `payment.failed` | Only `payment.failed` in transactional worker |
| `RazorpayWebhookIngressService` | `payment.webhook_received` | No (relay / ops) |
| `SuccessfulPaymentAcceptanceService` | `payment.successful`, `payment.reconciliation_required`, `enrolment.created`, `capacity.exhausted_after_payment` | Successful / reconciliation / enrolment yes; capacity exhausted **not** in transactional list |

Audit (`AuditService`) runs in parallel for sensitive actions; it is **not** the notification store. Delivery outcomes may also write notification audit actions (e.g. delivered / failed / dead / retry).

### 1.3 Where notifications are stored

| Store | Role |
|---|---|
| `outbox_messages` | Durable domain event bus (claim/lease/retry/dead). Migrations WP-01A (`20260720000001_*`, claim token `20260720000002_*`). |
| `notification_deliveries` | WP-07 email delivery ledger. Status: `pending \| processing \| delivered \| failed \| dead`. Channel: `email` only. Unique `(outbox_message_id, channel, template_key)`. Migration `20260724000001_wp07_dashboard_notifications.php`. |
| `verification_tokens` / `verification_challenges` | Identity delivery status (`pending \| delivered \| terminal`); sealed ciphertext cleared after finalisation (`DeliveryFinaliser`). |

Repositories: `OutboxRepository` / `PdoOutboxRepository`, `NotificationDeliveryRepository` / `PdoNotificationDeliveryRepository`.

### 1.4 How delivery currently happens

```
Domain TX → outbox_messages (pending)
        ↓
php bin/jobs.php notification:deliver
        ↓
claimByEventTypes (lease) → resolve recipient + template → EmailDeliveryPort::send
        ↓
finalise delivery row / token status + mark outbox published (lease-fenced)
```

- Provider I/O is **outside** DB transactions.
- Retries use outbox / delivery lease fields and `NotificationRetryPolicy`.
- Ops can reset failed/dead WP-07 deliveries via `AdminNotificationRetryService` (admin notification ops UI / API).
- Idempotency layers: outbox `idempotency_key`; delivery unique `(outbox, channel, template)`; provider send keys like `notif:{outboxId}:{channel}:{templateKey}`.

**Important:** Staging/production with `NOTIFICATION_EMAIL_ADAPTER=unavailable` fail closed at **send time** (`UnavailableEmailAdapter` throws). Identity issue/resend may surface as 503 via `NotificationCapability` when email/SMS is unavailable.

---

## 2. Current email adapters / providers

### 2.1 Contracts

| Port | Location | Method |
|---|---|---|
| `EmailDeliveryPort` | `src/Domain/Notifications/EmailDeliveryPort.php` | `send(EmailDeliveryMessage): ProviderReceipt` |
| `SmsOtpDeliveryPort` | `src/Domain/Notifications/SmsOtpDeliveryPort.php` | `send(SmsDeliveryMessage): ProviderReceipt` |

`EmailDeliveryMessage` carries `toAddress`, `templateKey`, `subject`, `bodyText`, `idempotencyKey`.  
`ProviderReceipt` carries `providerMessageId`.

There is **no** separate `EmailSender` / Mailgun/SES class today. `src/Infrastructure/Mail/` is empty (`.gitkeep` only). Implementations live under `src/Infrastructure/Notifications/`.

### 2.2 Implementations

| Class | Adapter key | Behaviour |
|---|---|---|
| `RecordingEmailAdapter` | `recording` | In-memory capture for tests; receipt `rec-email-N` |
| `LocalFileEmailAdapter` | `local_file` | Writes `.eml` under `NOTIFICATION_LOCAL_MAIL_PATH` (default `storage/mail`) |
| `UnavailableEmailAdapter` | default / unknown / prod-like empty | Throws `ExternalServiceException('Email delivery is not configured.')` |

**SMS (identity only):**

| Class | Key | Behaviour |
|---|---|---|
| `RecordingSmsAdapter` | `recording` | In-memory |
| `UnavailableSmsAdapter` | default | Throws |

No SES, SMTP, SendGrid, Postmark, or production SMS provider classes exist in-repo.

### 2.3 Config / selection

Env → `config/security.php` → `security.notifications.*` → `config/container.php` binds `EmailDeliveryPort` / `SmsOtpDeliveryPort`.

| Env var | Purpose |
|---|---|
| `NOTIFICATION_EMAIL_ADAPTER` | `recording` \| `local_file` \| `unavailable` (documented); anything else → unavailable |
| `NOTIFICATION_SMS_ADAPTER` | `recording` \| `unavailable` |
| `NOTIFICATION_LOCAL_MAIL_PATH` | Directory for local `.eml` files |
| `NOTIFICATION_DELIVERY_KEY` (+ previous / version) | Seals identity OTP/token payloads |

**Defaults** (`EnvironmentCapability`):

- Email: `local` → `local_file`; `testing`/`ci` → `recording`; else → `unavailable`
- SMS: `testing`/`ci` → `recording`; else → `unavailable`

**Guards:** `recording` / `local_file` email and `recording` SMS are **forbidden** in staging/production at config load and in `EnvironmentValidator`.

---

## 3. Existing notifications implemented

There is **no** `NotificationType` enum. Types are outbox `event_type` strings + code-owned `template_key`s (`TransactionalNotificationTemplateRegistry`).

### 3.1 Application events (email, WP-07 E2E)

| Outbox event | Template key | Subject pattern (code-owned) |
|---|---|---|
| `application.submitted` | `application_submitted` | Application submitted |
| `application.correction_requested` | `application_correction_requested` | Corrections required |
| `application.corrections_resubmitted` | `application_corrections_resubmitted` | Corrections received |
| `application.approved` | `application_approved_payment_pending` | Payment required |
| `application.rejected` | `application_rejected` | Application decision |
| `application.admitted` | `application_admitted` | Application admitted |

Channel: email only. No in-app centre (`PR-INAPP`). No transactional SMS.

### 3.2 Payment events

| Outbox event | Email? | Notes |
|---|---|---|
| `payment.failed` | Yes — `payment_failed` | Transactional worker |
| `payment.reconciliation_required` | Yes — `payment_reconciliation_required` | e.g. ambiguous / duplicate-success paths |
| `payment.successful` | Yes — `payment_successful` | Transactional worker |
| `payment.attempt_created` | No | Outbox → relay (if transport configured) |
| `payment.gateway_order_bound` | No | Relay only |
| `payment.webhook_received` | No | Relay only |
| `capacity.exhausted_after_payment` | No | Outbox exists; **not** in `TransactionalNotificationEventTypes` |

### 3.3 Enrolment events

| Outbox event | Template key | Wired? |
|---|---|---|
| `enrolment.created` | `enrolment_created` | Yes (email) |

### 3.4 Certificate events

**None for email.** Certificate issuance records audit (`certificate.issued`) / certificate domain events; it does **not** enqueue a transactional notification outbox type. Production readiness still lists certificates historically under future scope in places; Phase 1 certificate **display/PDF** exists, but **certificate email** is not implemented.

### 3.5 Identity (also email / SMS — separate ledger)

| Event | Channel | Store |
|---|---|---|
| `identity.email_verification.send` | Email | `verification_tokens` |
| `identity.password_reset.send` | Email | `verification_tokens` |
| `identity.mobile_otp.send` | SMS | `verification_challenges` |

These are **not** rows in `notification_deliveries` and are excluded from the WP-07 admin delivery list.

### 3.6 Learning / assessment

**No** notification templates or outbox email types for learning progress or assessments.

---

## 4. What is required to replace demo email with a production provider

### 4.1 Product / decision status

| Item | Status |
|---|---|
| Decision Log **WP01-B** (email provider; SES recommended) | **Pending** |
| Readiness **PR-EMAIL** | Required before pilot |
| Readiness **PR-SMS** / WP01-C | Required before production mobile verification; separate from email |
| **PR-TMPL** (editable templates CMS) | Future; keep code-owned templates for now |
| **PR-INAPP** | Future; not required for email swap |

Authority: `docs/product/DECISION_LOG.md`, `docs/product/WP01_DECISION_NOTE.md` (options B1 SES / B2 Postmark|Mailgun|SendGrid / B3 SMTP), `docs/product/PRODUCTION_READINESS_REGISTER.md`.

### 4.2 Engineering extension points (when approved)

1. **Implement** a production `EmailDeliveryPort` (recommended: `SesEmailAdapter` under `src/Infrastructure/Notifications/`, or populate `Infrastructure/Mail/` consistently with architecture).
2. **Wire** a new adapter key in `config/container.php` `EmailDeliveryPort::class` (today: only `recording` / `local_file` / else unavailable).
3. **Document** env vars (e.g. SES region, credentials / IAM role, from-address, configuration set) in `.env.example` and a deploy note; **never commit secrets**.
4. **Keep fail-closed:** do not allow `recording` / `local_file` in staging/production.
5. **Strengthen readiness (recommended):** treat `unavailable` email in staging/production as a hard readiness error once a real adapter exists (today send fails closed, but boot may still “pass” with unavailable).
6. **Workers unchanged** if `send()` → `ProviderReceipt` semantics are preserved (claim / idempotency / retry stay).
7. **Templates remain code-owned** until `PR-TMPL` / SA-04; SES HTML templates are optional — current bodies are plain text.
8. **SMS is out of scope for this email swap** — implement `SmsOtpDeliveryPort` + DLT separately under WP01-C / `PR-SMS`.
9. **Ops:** ensure `notification:deliver` (and related jobs) are scheduled (`PR-CRON`); optional alerting on dead deliveries (`PR-ALERT`).
10. **Optional later:** certificate-issued email would need a new outbox event + template registry entry — not part of the provider swap itself.

### 4.3 Explicit non-goals of a provider swap

- No redesign of outbox or state machines  
- No multi-tenant mail routing  
- No in-app notification centre  
- No change to “browser payment success ≠ confirmation” (payment emails still follow webhook/worker outcomes)

### 4.4 Suggested acceptance criteria (future work package)

- [ ] WP01-B product decision recorded (provider chosen)  
- [ ] Production adapter implements `EmailDeliveryPort`  
- [ ] Staging/production env uses real adapter; fake adapters rejected  
- [ ] Identity verification + password reset emails deliver via provider  
- [ ] WP-07 Mode A transactional emails deliver via provider  
- [ ] Idempotent retry does not duplicate provider sends (provider-side idempotency key where supported)  
- [ ] Secrets absent from HTML, logs, and audit payloads  
- [ ] UAT / pilot checklist updated; `PR-EMAIL` closed only after evidence  

---

## 5. Related documents

| Path | Relevance |
|---|---|
| `docs/product/WP07_IMPLEMENTATION_NOTE.md` | Transactional notifications |
| `docs/product/WP01_DECISION_NOTE.md` | Email/SMS provider options |
| `docs/product/DECISION_LOG.md` | VS-NOTIF-1, WP01-B, WP01-C |
| `docs/product/PRODUCTION_READINESS_REGISTER.md` | PR-EMAIL, PR-SMS, PR-TMPL, PR-INAPP |
| `docs/product/RC01_IMPLEMENTATION_NOTE.md` | UAT with local/recording; SES not closed |
| `docs/uat/UAT_NOTIFICATION_OPERATIONS.md` | Ops UAT |
| `docs/operations/WORKERS_AND_SCHEDULES.md` | Job cadence |
| `docs/operations/ALERT_CATALOGUE.md` | Outbox / delivery alerts |
| `docs/deployment/RAZORPAY_CONFIGURATION.md` | Sibling deploy pattern |
| `docs/technical/Academy_LMS_PHP_MySQL_Technical_Architecture_and_Coding_Standards_v1_1.md` | Target async / provider model |
| `.env.example` | Current adapter env catalogue |

### Key source directories

- `src/Domain/Notifications/`
- `src/Domain/Outbox/`
- `src/Application/Notifications/`
- `src/Infrastructure/Notifications/`
- `src/Infrastructure/Outbox/`

---

## Summary

Phase 1 already has a **complete notification pipeline**: transactional outbox → workers → `EmailDeliveryPort`, with Mode A application/payment/enrolment emails and identity email/SMS OTP. What is **missing for production mail** is solely a real `EmailDeliveryPort` implementation plus WP01-B decision / env / ops closure (`PR-EMAIL`). Certificate, learning, and in-app notifications are not part of the current email surface.
