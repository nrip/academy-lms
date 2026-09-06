# Email configuration — Academy LMS

Production SMTP delivery for identity + Mode A transactional notifications, including certificate-issued mail. Architecture overview: [`EMAIL_ARCHITECTURE_REVIEW.md`](./EMAIL_ARCHITECTURE_REVIEW.md).

## Environment variables

### Adapter selection

| Variable | Purpose |
|---|---|
| `NOTIFICATION_EMAIL_ADAPTER` | Legacy selector: `recording` \| `local_file` \| `unavailable` (defaults by `APP_ENV`) |
| `MAIL_DRIVER` | When `smtp` or `ses`, selects the production SMTP adapter (overrides `NOTIFICATION_EMAIL_ADAPTER`) |
| `NOTIFICATION_SMS_ADAPTER` | SMS OTP only — **not** part of this SMTP pack |
| `NOTIFICATION_LOCAL_MAIL_PATH` | Directory for `local_file` `.eml` output (demo/local) |

`MAIL_DRIVER=ses` uses the same SMTP client as `smtp` (Amazon SES SMTP endpoint via `MAIL_HOST`).

Staging/production **reject** `recording` and `local_file`.

### SMTP settings (required when `MAIL_DRIVER=smtp|ses`)

| Variable | Purpose | Example |
|---|---|---|
| `MAIL_HOST` | SMTP hostname | `email-smtp.ap-south-1.amazonaws.com` |
| `MAIL_PORT` | SMTP port | `587` (STARTTLS) or `465` (SSL) |
| `MAIL_ENCRYPTION` | Optional: `tls` \| `ssl` \| `none` | Default from port (`587`→`tls`, `465`→`ssl`) |
| `MAIL_USERNAME` | SMTP username | SES SMTP user |
| `MAIL_PASSWORD` | SMTP password | SES SMTP password |
| `MAIL_FROM_ADDRESS` | From address (verified in SES) | `noreply@academy.example` |
| `MAIL_FROM_NAME` | From display name | `Academy LMS` |

Also keep `NOTIFICATION_DELIVERY_KEY` configured (identity OTP sealing).

## Provider setup (Amazon SES SMTP)

1. In AWS SES, verify the sending domain or `MAIL_FROM_ADDRESS`.
2. Create SMTP credentials for the SES region.
3. Move the account out of sandbox (or verify all recipients) before pilot.
4. Set env:

```bash
APP_ENV=production
NOTIFICATION_EMAIL_ADAPTER=unavailable   # ignored when MAIL_DRIVER is set
MAIL_DRIVER=ses
MAIL_HOST=email-smtp.ap-south-1.amazonaws.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=AKIA...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@your-domain.example
MAIL_FROM_NAME="Your Academy"
```

Any standards-compliant SMTP server works the same way with `MAIL_DRIVER=smtp`.

## Worker command

Email is delivered asynchronously from the outbox:

```bash
php bin/jobs.php notification:deliver
```

Schedule this on a short cadence (see `docs/operations/WORKERS_AND_SCHEDULES.md`). Demo helper:

```bash
php bin/jobs.php demo:process
```

## Certificate issued email

When a completion certificate is issued, the issuance service enqueues `certificate.issued` (idempotent per certificate id). The transactional worker renders template `certificate_issued` with:

- learner name  
- course name  
- certificate link (`{APP_URL}/certificates/{id}`)  

No new payment/admission state-machine changes.

## Local / demo testing

```bash
# Demo file capture
APP_ENV=local
NOTIFICATION_EMAIL_ADAPTER=local_file
NOTIFICATION_LOCAL_MAIL_PATH=storage/mail
# leave MAIL_DRIVER unset

php bin/jobs.php notification:deliver
ls storage/mail
```

Automated:

```bash
./vendor/bin/phpunit tests/Unit/Infrastructure/Notifications/SmtpEmailAdapterTest.php
./vendor/bin/phpunit tests/Unit/Application/Notifications/EmailAdapterSelectionTest.php
./vendor/bin/phpunit tests/Integration/Notifications/TransactionalNotificationDeliveryTest.php
./vendor/bin/phpunit tests/Integration/Notifications/CertificateIssuedNotificationTest.php
./vendor/bin/phpunit tests/Security/Wp07NotificationSecurityTest.php
```

## Security checklist

- [ ] Never commit SMTP passwords or SES SMTP credentials  
- [ ] Fake adapters off in staging/production  
- [ ] From-address verified with the provider  
- [ ] `notification:deliver` scheduled and monitored (`PR-ALERT` / dead deliveries)  
- [ ] SMS remains separately gated (`PR-SMS`) — not enabled by this pack  
