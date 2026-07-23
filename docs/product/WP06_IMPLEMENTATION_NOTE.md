# WP-06 — Webhook, Admission and Enrolment

**Branch:** `slice/wp06-webhook-admission-enrolment`  
**Roadmap ID:** WP-06 Webhook, Admission and Enrolment  
**Authority:** SRS v6.1 REQ-PAY-3/4/5/11, REQ-ENR-1, REQ-BATCH-5, PAY-ATTEMPT-1, VERTICAL_SLICE_ROADMAP WP-06.

## Scope

| In | Out |
|---|---|
| Razorpay webhook ingress + raw-body HMAC | Refunds / dispute workers |
| Durable `payment_webhook_events` | Offline mark-paid |
| Webhook processor + reconciliation worker | Invoices |
| Payment → `successful` / `reconciliation_pending` / `failed` | Course player / progress |
| Application `payment_pending` → `admitted` | Production email/SMS delivery |
| Capacity lock + Enrolment create | Learner content access (beyond result UI) |
| Complete `EnrolmentStateMachine` matrix | |
| Finance reconcile list + retry | |

## Decisions (no blockers)

1. **Webhook events processed:** `payment.captured`, `order.paid` → capture success path; `payment.failed` → fail if still pending; `payment.authorized` → receipt only (no domain status); unknown events → store + mark ignored.
2. **One success:** first valid capture sets `successful_marker=1`, admits Application, creates Enrolment. Additional captures → `reconciliation_pending` (no second Enrolment / capacity).
3. **Full batch after capture:** Payment → `reconciliation_pending`; Application stays `payment_pending`; no Enrolment; outbox `capacity.exhausted_after_payment`. No silent refund.
4. **Capacity:** `batches FOR UPDATE` + count Enrolments (`scheduled`/`active`) + Admitted Applications without Enrolment vs `max_capacity` (REQ-BATCH-5). No pre-payment seat reservation (WP-02 preserved).
5. **Enrolment lifecycle on create:** `scheduled` if `batch.starts_at > now`, else `active`. Academic status `not_started` when Active, else NULL.
6. **Signature:** Razorpay `X-Razorpay-Signature` = HMAC-SHA256(raw body, webhook secret); `hash_equals`; verify before JSON trust.
7. **No gateway I/O inside DB transactions.**

## Transitions

**Payment:** `pending→successful|failed|reconciliation_pending`; `reconciliation_pending→successful|failed` (system/finance).  
**Application:** `payment_pending→admitted` (actor `system`).  
**Enrolment matrix:** full SRS §18.2 (create uses factory initial status only).

## Permissions / routes

- `POST /webhooks/razorpay` — signature auth, no CSRF/session
- `GET /finance/reconciliation`, `GET /finance/payments/{id}`, `POST /finance/payments/{id}/reconcile`
- Keys: `finance.payment.reconcile`, `finance.payment.retry_reconciliation` (+ existing view)

## Contradictions

None blocking. Roadmap branch name differs (`admit` vs `admission`); this branch follows the delivery command.
