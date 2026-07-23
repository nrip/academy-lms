# WP-05 — Payment Checkout

**Branch:** `slice/wp05-payment-reconciliation`  
**Roadmap ID:** WP-05 Payment Checkout  
**Authority:** VERTICAL_SLICE_ROADMAP WP-05, AGENTS.md §6.3 / REQ-PAY-7, PAY-ATTEMPT-1, VS-SCOPE-1, Q1=A / Q2=AGENTS (product 2026-07-23).

## Scope

| In | Out (WP-06+) |
|---|---|
| Payment attempts (`application_id` NOT NULL) | Webhook ingress / signature verify / durable gateway events |
| Immutable fee/GST/currency snapshot (minor units) | Reconciliation worker / cron |
| Razorpay order creation (+ local/testing Fake) | Payment → `successful` from browser or fake webhook |
| A-06 checkout + A-09 confirming UI | Application → `admitted` |
| Complete `PaymentStateMachine` matrix + tests | Enrolment / capacity / invoice |
| In-flight attempt gate (Created/Pending) | Offline mark-paid (REQ-PAY-8) |
| Finance payment list/detail (view only) | Refunds / disputed handling workers |

## Decisions locked

- **Q1=A:** Accepted capture / Application → Admitted / Enrolment are **WP-06**. WP-05 leaves Application at `payment_pending` and Payment at `pending` after order bind (browser return is informational).
- **Q2=AGENTS:** Exact 10 statuses — no `authorized`, `captured`, or `processing` domain statuses.
- **In-flight:** Reject a second attempt while any attempt is `created` or `pending`. New attempt only after prior reaches `failed`, `cancelled`, or `expired`. History preserved. Also refuse initiate if any `successful` / `reconciliation_pending` exists (PAY-ATTEMPT-1 pre-guard).

## Payment statuses (snake_case)

`created`, `pending`, `successful`, `failed`, `cancelled`, `expired`, `reconciliation_pending`, `refunded`, `partially_refunded`, `disputed`.

### Transition matrix (WP-05 implements full matrix; HTTP only uses Created→Pending and failure edges)

| From | To |
|---|---|
| created | pending, failed, cancelled |
| pending | successful, failed, cancelled, expired, reconciliation_pending |
| successful | refunded, partially_refunded, disputed, reconciliation_pending |
| partially_refunded | refunded, disputed |
| reconciliation_pending | successful, failed, cancelled, refunded |
| disputed | successful, refunded |
| failed / cancelled / expired / refunded | ∅ |

Actors: `learner` (cancel abandoned created), `system` (gateway bind / WP-06 webhook path), `finance` (reconciliation resolution — matrix only), `admin`.

## Amount snapshot

Derived at initiation from locked Application → Batch (`fee_override` ?? CourseVersion `standard_fee`) + CourseVersion `gst_rate` + `currency`, via integer paise (`PaymentAmountSnapshot` / `FeeDisplay` arithmetic). Persisted on Payment; never recalculated from mutable catalogue after create. Client amount ignored.

## Flow (Mode A)

1. Learner GET payment page (`payment_pending`, own Application).
2. POST initiate → lock Application + payments → create `created` Payment + audit/outbox → commit.
3. Gateway `createOrder` **outside** DB txn → second txn binds `provider_order_id` and `created→pending`.
4. Client receives key id + order id + display amounts only.
5. Browser return → A-09 “Confirming payment…” — **no** Successful, **no** Application transition.
6. WP-06 webhook/reconcile → Successful + Admitted + Enrolment.

## Permissions

| Key | Role |
|---|---|
| `payment.initiate_own` | applicant |
| `payment.view_own` | applicant |
| `payment.retry_own` | applicant |
| `finance.payment.view` | finance (existing) |

No document keys for Finance. No webhook/reconcile permissions in WP-05.

## Routes

- `GET /applications/{id}/payment` — A-06
- `POST /applications/{id}/payments` — initiate
- `GET /applications/{id}/payments/{paymentId}` — attempt detail / checkout config
- `POST /applications/{id}/payments/{paymentId}/checkout-return` — informational return
- `GET /applications/{id}/payment-result` — A-09
- `GET /finance/payments` / `GET /finance/payments/{paymentId}` — SoD-safe list/detail

## Outbox (WP-05)

- `payment.attempt_created`
- `payment.gateway_order_bound`
- `payment.failed` (gateway create failure)

No `application.payment_completed` / Admitted events here.

## Contradictions

None blocking after Q1=A / Q2=AGENTS. Architecture cron “Processing” is not a Payment status. Application `Cancelled` remains deferred (WP-03 note).