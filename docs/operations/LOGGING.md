# Logging — Academy LMS (RC-01)

**Authority:** RC-01 section S  
**Implementation:** Monolog via `Academy\Infrastructure\Logging\LoggerFactory` with `SensitiveDataProcessor`  
**Request correlation:** `Academy\Http\Middleware\RequestIdMiddleware` (`X-Request-Id` / attribute `request_id`)

---

## Request ID

| Behaviour | Detail |
|---|---|
| Incoming | Honour client `X-Request-Id` when present; otherwise generate |
| Outgoing | Echo `X-Request-Id` on responses |
| Health | `/health/live` and `/health/ready` include `request_id` in JSON |
| Errors | Exception handler logs and safe error payloads include `request_id` |

Workers should log a stable worker id (`hostname:pid` from `bin/jobs.php`) and entity ids — not secrets.

---

## What to log

| Prefer | Avoid |
|---|---|
| `request_id`, route, HTTP status class | Passwords, password hashes |
| Actor user id (internal) | OTP / TOTP secrets / recovery codes |
| Entity ids (application_id, payment_id) | Session tokens, CSRF tokens |
| Exception **category** / class | Raw SQL with bound secrets |
| Payment **public** references | Webhook signatures, raw webhook bodies |
| Template keys / delivery status | Full email/mobile; document bytes; object keys when sensitive |
| Redacted provider error summaries | Gateway secrets, DB credentials, peppers |

---

## Redaction (implemented)

`SensitiveDataProcessor` denies (and replaces with `[REDACTED]`) context/extra keys matching sensitive names, including among others:

`password`, `otp`, `totp_secret`, `recovery_code`, `session_token`, `csrf_token`, `secret`, `token`, `ciphertext`, `pepper`, `provider_body`, …

Query-string scrubbing redacts `token` / `otp` / `confirmation` parameter values inside message strings.

**Limits:** Application Monolog redaction does **not** automatically scrub web-server access logs, reverse-proxy logs, CDN, or APM. Those layers need their own scrubbing — treat as an ops prerequisite.

`AuditRedactor` applies similar defence-in-depth for audit payloads.

---

## Log destinations (current)

| Item | Behaviour |
|---|---|
| Path | Configured under application logging path (typically under `storage/`) |
| Format | Line or JSON per config |
| Level | From config (`LOG_LEVEL` / equivalent) |

There is **no** in-repo claim of centralized log shipping. Wire Fluent Bit / CloudWatch / etc. per hosting pack.

---

## Rotation and retention (placeholders)

Set concrete values per environment before pilot. Placeholders for planning:

| Environment | Rotation (placeholder) | Hot retention | Archive retention | Notes |
|---|---|---|---|---|
| local | size 50MB or daily | 7 days | optional | Developer machines |
| uat | daily | 14–30 days | 90 days | Enough for UAT defect cycles |
| staging | daily | 30 days | 180 days | Align with privacy review |
| production | daily + size cap | **TBD — PR-RETENTION** | **TBD — PR-RETENTION** | Must pass privacy/security review |

Suggested tools (examples only): `logrotate`, container log driver limits, or platform-native retention.

Example `logrotate` snippet (**example**):

```text
/var/log/academy-lms/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

---

## Operational checks

1. Trigger a controlled error; confirm log line has `request_id` and no password.
2. Complete a login failure; confirm OTP/password absent.
3. Process a webhook failure; confirm signature/raw body absent.
4. Confirm disk alert `ALT-DISK-LOG` thresholds match retention reality.

---

## Related readiness

Unresolved production logging/privacy items remain open on [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) (`PR-RETENTION`, `PR-ALERT`).
