# Razorpay configuration — Academy LMS

Single-deployment Razorpay checkout for Mode A admissions. This is **not** multi-tenant: one Razorpay account per environment.

Authoritative behaviour remains webhook-first (SRS / Technical Architecture): the browser return page is informational only. Payment success and admission are driven by signed webhooks and workers, using the existing `PaymentStateMachine` / admission flow.

## Required environment variables

| Variable | Purpose | Notes |
|---|---|---|
| `RAZORPAY_KEY_ID` | Public key id for Checkout.js and API Basic auth | Safe to expose to the browser as Checkout `key` |
| `RAZORPAY_KEY_SECRET` | API secret | **Server only** — never put in templates, JS, logs, or audit payloads |
| `RAZORPAY_WEBHOOK_SECRET` | HMAC secret for `X-Razorpay-Signature` | **Server only** — configure in Razorpay Dashboard → Webhooks |

Related (optional / local):

| Variable | Purpose |
|---|---|
| `PAYMENTS_FAKE_GATEWAY` | When explicitly enabled in `local` / `testing` / `ci` / `uat`, uses the fake gateway + demo capture instead of live Razorpay. **Forbidden** in `staging` / `production`. |
| `PAYMENTS_RECONCILE_PENDING_STALE_SECONDS` | Reconciliation worker stale pending window (default `1800`) |

### Staging / production gates

With the fake gateway disabled, staging and production **fail closed** at environment validation if any of `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, or `RAZORPAY_WEBHOOK_SECRET` is empty.

Never commit real secrets. Inject them via the host secret store / deployment env.

Example `.env` fragment (test mode keys only):

```bash
PAYMENTS_FAKE_GATEWAY=0
RAZORPAY_KEY_ID=rzp_test_xxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxx
RAZORPAY_WEBHOOK_SECRET=xxxxxxxx
```

## Adapter selection

`PaymentGateway` is resolved in `config/container.php`:

1. Fake gateway — only when `PAYMENTS_FAKE_GATEWAY` is enabled **and** the environment allows fake adapters.
2. Else `RazorpayPaymentGateway` — when key id + secret are both non-empty.
3. Else `UnconfiguredPaymentGateway` — checkout initiation fails closed.

## Webhook setup (Razorpay Dashboard)

1. Create a webhook pointing at:
   - `POST https://<your-host>/webhooks/razorpay`
2. Subscribe at least to:
   - `payment.captured`
   - `payment.failed`
   - (recommended) `order.paid`, `payment.authorized`
3. Copy the webhook secret into `RAZORPAY_WEBHOOK_SECRET`.
4. Confirm the endpoint is reachable over HTTPS (force HTTPS in production via `FORCE_HTTPS` / reverse proxy).

### What the application does

| Step | Behaviour |
|---|---|
| Ingress | Verify `X-Razorpay-Signature` = HMAC-SHA256(raw body, webhook secret); reject invalid signatures with **401** and **no** durable receipt |
| Persist | Insert `payment_webhook_events` keyed by provider + event id (idempotent duplicates return `200` + `duplicate=true`) |
| Process | Worker `php bin/jobs.php payment:webhook-process` drives existing payment / admission transitions |
| Capture | `payment.captured` / `order.paid` → successful acceptance path (may admit + create Enrolment) |
| Failure | `payment.failed` → payment `pending → failed` if still pending; **no** admission / enrolment |
| Browser | Checkout return / “Confirming payment…” **never** marks payment successful |

Ensure workers and schedules are running in each environment (see `docs/operations/WORKERS_AND_SCHEDULES.md`).

## Checkout UI

Learner flow (unchanged routes):

1. `GET /applications/{id}/payment` → fee summary  
2. `POST /applications/{id}/payments` → creates payment attempt + Razorpay order; stores `provider_order_id`; payment becomes `pending`  
3. `GET /applications/{id}/payments/{paymentId}` → opens Razorpay Checkout.js with public key + order id  
4. After Checkout, browser POSTs checkout-return → “Confirming payment…”  
5. Razorpay delivers webhook → worker processes → result page / dashboard update  

Demo capture (`…/demo-capture`) appears only when the fake gateway is available; it still injects a **signed webhook** rather than trusting the browser.

## Local testing instructions

### A. Fake gateway (no Razorpay account)

```bash
# .env
APP_ENV=local
PAYMENTS_FAKE_GATEWAY=1
# Webhook secret may use the soft local default when unset in local/testing/ci

composer demo-serve   # or your usual local server
# Walk application to payment_pending, open payment attempt, “Complete demo payment”
php bin/jobs.php demo:process
# or: php bin/jobs.php payment:webhook-process
```

### B. Razorpay test mode (live Checkout + webhook)

1. Set `PAYMENTS_FAKE_GATEWAY=0` (or leave unset outside testing/ci defaults).
2. Set test-mode `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`.
3. Expose `POST /webhooks/razorpay` publicly (ngrok / Cloudflare tunnel / similar) and register that URL in the Razorpay Dashboard.
4. Complete Checkout with Razorpay test cards.
5. Confirm:
   - Invalid signature → no `payment_webhook_events` row  
   - Valid `payment.captured` → payment successful + admission (after worker)  
   - Valid `payment.failed` → payment failed; application stays `payment_pending`  

### C. Automated suite

```bash
./vendor/bin/phpunit tests/Unit/Infrastructure/Payments/RazorpayPaymentGatewayTest.php
./vendor/bin/phpunit tests/Unit/Infrastructure/Payments/RazorpayWebhookSignatureVerifierTest.php
./vendor/bin/phpunit tests/Integration/Payments/WebhookAdmissionEnrolmentFlowTest.php
./vendor/bin/phpunit tests/Integration/Payments/PaymentCheckoutFlowTest.php
./vendor/bin/phpunit tests/Http/PaymentCheckoutHttpTest.php
./vendor/bin/phpunit tests/Http/WebhookAdmissionHttpSecurityTest.php
```

## Security checklist

- [ ] Secrets only in environment / secret manager — not in git  
- [ ] Fake gateway off in staging/production  
- [ ] Webhook URL is HTTPS and signature-verified  
- [ ] Key secret and webhook secret never appear in HTML, JS, or audit payloads  
- [ ] Operators understand browser success ≠ payment confirmation  
- [ ] `payment:webhook-process` and reconciliation jobs are scheduled  

## Related documents

- `docs/product/WP05_IMPLEMENTATION_NOTE.md` — checkout  
- `docs/product/WP06_IMPLEMENTATION_NOTE.md` — webhook / admission  
- `docs/operations/WORKERS_AND_SCHEDULES.md` — workers  
- `docs/product/PRODUCTION_READINESS_REGISTER.md` — item **PR-RZP**
