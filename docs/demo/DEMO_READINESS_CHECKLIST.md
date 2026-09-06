# Demo readiness checklist

Use before any Product Owner or prospective-user session.

## Environment

- [ ] Branch `demo/mode-a-user-demo` checked out
- [ ] `.env` present with `APP_ENV=local` (or `uat`)
- [ ] `PAYMENTS_FAKE_GATEWAY=1`
- [ ] `DOCUMENTS_STORAGE_DRIVER=local`
- [ ] `DOCUMENTS_FAKE_SCANNER=1`
- [ ] `NOTIFICATION_EMAIL_ADAPTER=local_file` (or `recording`)
- [ ] MySQL reachable
- [ ] `composer install` and `composer assets:install` completed

## Data

- [ ] `php bin/jobs.php demo:prepare --confirm` succeeded (twice if proving idempotency)
- [ ] Printed persona emails and password reviewed
- [ ] Course **Certificate Course in Obesity and Metabolic Health** visible in catalogue
- [ ] Open batch visible with seats and application dates
- [ ] Seeded scenarios present (draft through full-batch)

## Runtime

- [ ] `composer demo-serve` running (sets `upload_max_filesize=10M` / `post_max_size=16M`)
- [ ] `/login` loads with Academy branding
- [ ] Learner login → `/dashboard`
- [ ] Reviewer login → `/reviewer/applications`
- [ ] Finance login → `/finance/reconciliation` or payments
- [ ] Ops login → `/admin/notifications`
- [ ] Role-appropriate navigation only (no broken links)

## Journey smoke (same day)

- [ ] Learner can open course + batch and create/submit Application
- [ ] `demo:process` scans documents
- [ ] Reviewer can claim and approve to payment pending
- [ ] **Complete demo payment** shows Confirming first
- [ ] Second `demo:process` admits + creates Enrolment
- [ ] Finance sees Payment; cannot open document downloads
- [ ] Notification list shows masked recipients; retry works on approved failed sample
- [ ] `demo:process` re-run is safe (no duplicate Enrolment)

## Negative / safety

- [ ] Staging/production-like `APP_ENV` refuses `demo:prepare` / fake adapters
- [ ] No production secrets in `.env` committed
- [ ] Presenter knows features that are **out of scope** (player, assessments, certificates, refunds)

## Facilitation

- [ ] [`DEMO_SCRIPT.md`](./DEMO_SCRIPT.md) printed or open
- [ ] Fallback scenario markers noted
- [ ] Feedback template ready: [`USER_FEEDBACK_TEMPLATE.md`](./USER_FEEDBACK_TEMPLATE.md)

**Ready for demo?** Yes / No — date ______ facilitator ______
