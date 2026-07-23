# WP-06 — Webhook, Admission and Enrolment

**Branch:** `slice/wp06-webhook-admission-enrolment` (not pushed)  
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

## Coverage follow-up (material correctness)

### Suite totals
| Gate | Result |
|---|---|
| Full PHPUnit | **1045 tests / 2747 assertions** (was 1024 / 2571) |
| WP-06 focused + multi-process + rollback + HTTP/security | 38 tests / 248 assertions in focused filter; concurrency class alone 5 scenarios |
| Migration `20260723000008` rollback → reapply | Pass |
| PHPStan | Pass (0 errors) |
| php-cs-fixer dry-run | Pass (0 files) |
| composer audit | No advisories |
| `git diff --check` | Pass |
| Branch tip / diff vs `main` | recorded in work-package report at stop (not embedded here to avoid self-referential HEAD churn) |

### A. Multi-process concurrency (separate PHP + PDO)

| Scenario | Final-state assertions |
|---|---|
| Two Payments racing same Application | Exactly one `successful` + `successful_marker=1`; other `reconciliation_pending`; Application admitted once; one Enrolment; occupied capacity +1; one each of `payment.successful` / `application.admitted` / `enrolment.created` outbox; one `pending→successful` history row |
| Two Applications racing final Batch seat (`max_capacity=1`) | One admitted + one Enrolment; occupied = 1; loser Payment `reconciliation_pending` + Application stays `payment_pending`; one `capacity.exhausted_after_payment` outbox |
| Webhook worker vs reconciliation worker | Payment stays `successful` (not downgraded); admit/Enrolment once; no duplicate success history/audit/outbox |
| Stale reconcile lease after newer success | Stale worker exits `stale_version` without mutation; success preserved |

Workers: `payment_accept_worker.php`, `payment_reconcile_worker.php`, `payment_reconcile_stale_worker.php` (+ existing webhook workers).

### B. HTTP / security
- Malformed JSON webhook → 422, no receipt
- Oversized body (>1_048_576) → 422, no receipt
- Invalid signature → 401, no trusted receipt; signature/raw body absent from audit
- Duplicate valid webhook → 200 + `duplicate=true`, single receipt row
- Finance reconcile retry: CSRF required (403); permission required (403); pending_verification Finance (403); suspended Finance (403 after session invalidate)
- `FakeWebhookSigner` / `FakePaymentGateway` denied outside local|testing|ci
- Webhook secret / signature / raw body do not appear in audit or Finance HTML

### C. Forced-failure rollback
`SuccessfulPaymentAcceptanceRollbackTest` injects failure after: successful_marker assignment, payment history, Application transition, Enrolment insert, outbox, audit. Asserts full rollback: Payment pending, marker NULL, Application `payment_pending`, no Enrolment, capacity unchanged, no partial history/audit/outbox.

### Fixes required by new tests
1. **Lock order** in `SuccessfulPaymentAcceptanceService`: Application → all Payments → Batch → Enrolment (avoids same-Application Payment race deadlock).
2. **`TransactionManager::runWithDeadlockRetry`** used by webhook capture acceptance and reconciliation claim processing; accept worker retries InnoDB 1213/1205.
3. Shared-catalogue seeding via `ReviewerTestFixture` `catalogue` option for final-seat race.
4. Tear down `PAYMENTS_FAKE_GATEWAY` so staging/production probe tests are not polluted.

### Out of scope (unchanged)
No package redesign; no broad cosmetic tests; not pushed.
