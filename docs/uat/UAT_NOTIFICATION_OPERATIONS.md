# UAT Notification Operations — Academy LMS (RC-01)

**Authority:** RC-01 section M  
**Persona default:** `ops@uat.example.test` (Super Admin)  
**Overview:** [UAT_OVERVIEW.md](./UAT_OVERVIEW.md) · **Accounts:** [UAT_ACCOUNTS.md](./UAT_ACCOUNTS.md)

Transactional email only in this slice. Production SES is not required for UAT when local_file/recording adapters are explicitly configured.

Workers:

```bash
php bin/jobs.php outbox:relay
php bin/jobs.php notification:deliver
```

---

## UAT-N-01 — Notification list and detail

| Field | Value |
|---|---|
| **Test ID** | UAT-N-01 |
| **Persona** | Ops |
| **Preconditions** | MFA complete; seeded samples (`uat.pending`, `uat.delivered`, `uat.retryable`, `uat.dead`) or live deliveries |
| **Steps** | 1. GET `/admin/notifications`. 2. Open `/admin/notifications/{id}` for each status sample. |
| **Expected result** | List/detail load; statuses distinguishable; template key/version visible; no document or payment raw payloads. |
| **Evidence** | List screenshot; detail field checklist. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-02 — Delivered status

| Field | Value |
|---|---|
| **Test ID** | UAT-N-02 |
| **Persona** | Ops |
| **Preconditions** | `uat.delivered` sample or live delivered row |
| **Steps** | 1. Open delivered detail. 2. Confirm delivered timestamp / provider message id if present. |
| **Expected result** | Status `delivered`; retry not required; recipient shown masked only. |
| **Evidence** | Detail fields. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-03 — Retryable failure

| Field | Value |
|---|---|
| **Test ID** | UAT-N-03 |
| **Persona** | Ops |
| **Preconditions** | `uat.retryable` (failed + provider_transient) or induce transient failure |
| **Steps** | 1. Open detail. 2. Confirm failure category and next attempt metadata. |
| **Expected result** | Failure classified retryable; no full provider body; no cleartext full email. |
| **Evidence** | Failure category; masked recipient. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-04 — Dead-letter

| Field | Value |
|---|---|
| **Test ID** | UAT-N-04 |
| **Persona** | Ops |
| **Preconditions** | `uat.dead` sample |
| **Steps** | 1. Open dead delivery. 2. Confirm dead marker/timestamp. |
| **Expected result** | Status `dead`; visible for ops; no automatic silent drop without record. |
| **Evidence** | Detail screenshot. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-05 — Privileged retry

| Field | Value |
|---|---|
| **Test ID** | UAT-N-05 |
| **Persona** | Ops; Finance/Learner negative |
| **Preconditions** | Retryable or dead delivery id; `notification.retry` permission on ops |
| **Steps** | 1. As Ops, POST `/admin/notifications/{id}/retry` with CSRF. 2. Run `notification:deliver` if needed. 3. As Finance/Learner, attempt same POST. |
| **Expected result** | Ops retry accepted per policy; non-privileged actors denied; idempotent enough not to create contradictory double-sends beyond designed attempts. |
| **Evidence** | Status codes; delivery attempt count change. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-06 — Masked recipient

| Field | Value |
|---|---|
| **Test ID** | UAT-N-06 |
| **Persona** | Ops |
| **Preconditions** | Any delivery detail |
| **Steps** | 1. Inspect recipient display. 2. View page source. |
| **Expected result** | Recipient masked (e.g. `lea***@uat.example.test`); full address not in HTML; hash may exist server-side only. |
| **Evidence** | Masked value copy. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-07 — No rendered sensitive body

| Field | Value |
|---|---|
| **Test ID** | UAT-N-07 |
| **Persona** | Ops |
| **Preconditions** | Delivery detail for identity or transactional mail |
| **Steps** | 1. Confirm UI does not render full email HTML/text body with OTP/links secrets. |
| **Expected result** | Ops UI shows metadata/status — not a mailbox of sensitive rendered bodies. |
| **Evidence** | Negative checklist. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-08 — No document / payment payload leakage

| Field | Value |
|---|---|
| **Test ID** | UAT-N-08 |
| **Persona** | Ops |
| **Preconditions** | Deliveries tied to application/payment events if present |
| **Steps** | 1. Inspect detail JSON/HTML for document object keys, signed URLs, webhook payloads, card/gateway secrets. |
| **Expected result** | None present. |
| **Evidence** | Negative search notes. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |

---

## UAT-N-09 — Fake / local delivery artifact inspection

| Field | Value |
|---|---|
| **Test ID** | UAT-N-09 |
| **Persona** | Ops |
| **Preconditions** | `NOTIFICATION_EMAIL_ADAPTER=local_file` or `recording` explicitly configured for UAT; path writable |
| **Steps** | 1. Trigger a notification (e.g. password reset or application event). 2. Run outbox relay + notification deliver. 3. Inspect artifact under configured local mail path (default `storage/mail`) or recording store. |
| **Expected result** | Artifact exists for debugging UAT only; production-like envs must not use these adapters. |
| **Evidence** | Artifact filename/id only; do not paste OTP. |
| **Result** | |
| **Defect ref** | |
| **Severity** | |
