# UAT Accounts — Academy LMS (RC-01)

**Audience:** UAT testers and operators  
**Seed command:** `php bin/jobs.php uat:seed`  
**Reset:** `php bin/jobs.php uat:reset --confirm`  
**Gate:** Allowed only when `APP_ENV` is `local` | `testing` | `ci` | `uat`. Refused for `staging` | `production`.

These identities are **synthetic**. They are not production accounts and must never be reused outside UAT/local/CI.

---

## Password policy

| Source | Value |
|---|---|
| Environment variable | `UAT_SEED_PASSWORD` |
| Default if unset | `Uat-Demo-Passw0rd!` |

The default is a **documented UAT demo password**, not a production secret. Prefer setting `UAT_SEED_PASSWORD` per environment and rotating it when UAT is shared across teams. Do not commit environment-specific passwords to the repository.

Seeding hashes the password with Argon2id and upserts personas idempotently.

---

## Personas

| Persona | Login (email) | Mobile (E.164) | Roles | MFA expectation | Intended test path |
|---|---|---|---|---|---|
| Learner | `learner@uat.example.test` | `+919900000001` | Applicant | Not required for learner routes | Catalogue → application → documents → payment → dashboard |
| Reviewer | `reviewer@uat.example.test` | `+919900000002` | Credential Reviewer | Privileged session may require MFA enrolment/challenge | Queue → claim → document decisions → approve/reject |
| Finance | `finance@uat.example.test` | `+919900000003` | Finance Administrator | Privileged session may require MFA enrolment/challenge | Payments list/detail → reconciliation; prove no document access |
| Ops / Notification Admin | `ops@uat.example.test` | `+919900000004` | Super Admin | Privileged session may require MFA enrolment/challenge | Notification list/detail/retry; ops smoke |
| Multi-permission | `multi@uat.example.test` | `+919900000005` | Applicant + Credential Reviewer | Privileged reviewer routes may require MFA | Permission precedence / landing resolver; scoped reviewer path |

Domain constant: `uat.example.test` (`UatSeedService::EMAIL_DOMAIN`).

---

## Authentication / MFA bootstrap behaviour

Seeded users are created as:

- `account_status = active`
- email and mobile marked verified
- password set from `UAT_SEED_PASSWORD` / default
- **no MFA device enrolled** by seed

Credential Reviewer, Finance Administrator, and Super Admin are MFA-mandatory roles (AGENTS.md §7.3). On first privileged login after seed:

1. Password authentication succeeds.
2. Auth stage may become `mfa_enrolment_required` (or challenge if a device already exists).
3. Only MFA allow-list routes (`mfa.totp.enrol`, `mfa.totp.verify`, `mfa.recovery.use`) are usable until assurance is complete.
4. After TOTP enrolment + verify, privileged landings (reviewer queue, finance payments, notification ops) become available.

**UAT tip:** Budget time for MFA enrolment on reviewer / finance / ops before those scripts. Store recovery codes in the tester’s secure notes for the UAT cycle only — never in the defect log or git.

Learner journeys do not require MFA.

---

## Permissions and starting scenarios

After a successful `uat:seed` (and demo catalogue present), representative data includes:

| Marker / scenario | Owner | Notes for testers |
|---|---|---|
| Catalogue `WP02-DEMO-OBESITY-101` | Shared | Published course + open batch `WP02-DEMO-OBESITY-101-OPEN` |
| `UAT-DRAFT-001` | Learner | Draft application |
| `UAT-REVIEW-001` | Learner | Under review (reviewer queue) |
| `UAT-CORRECT-001` | Learner | Resubmission requested |
| `UAT-PAYPEND-001` | Learner | Payment pending (+ pending payment row when schema allows) |
| `UAT-AWAIT-001` | Learner | Awaiting verification / reconciliation_pending payment |
| `UAT-ADMIT-SCHED-001` | Learner | Admitted + Scheduled enrolment |
| `UAT-ADMIT-ACTIVE-001` | Learner | Admitted + Active enrolment |
| `UAT-REJECT-001` | Learner | Rejected |
| Notification samples `uat.pending` / `uat.delivered` / `uat.retryable` / `uat.dead` | Ops list | Masked recipient; no sensitive body |

Reviewer and multi personas receive batch scope on the demo open batch when the scope table is available.

If catalogue is missing, seed reports `catalogue:missing` — run:

```bash
vendor/bin/phinx seed:run -s Wp02DemoCatalogueSeeder
php bin/jobs.php uat:seed
```

---

## Post-login landings (permission precedence)

Implemented resolver order (highest first):

1. Reviewer queue (if reviewer permissions + complete MFA assurance)
2. Finance payments / reconciliation
3. Notification ops
4. Learner dashboard
5. Profile / courses fallback

`return_to` is allow-listed only (no open redirect). Non-active accounts never receive privileged landings.

---

## How to reset a password

### Option A — Forgot-password flow (preferred for UAT realism)

1. Open `/forgot-password`.
2. Submit the persona email.
3. With local/recording email adapter, inspect the delivery artifact under the configured local mail path (typically `storage/mail`) or recording store — **not** production SES.
4. Complete `/reset-password` using the link/token from the artifact.
5. Confirm login with the new password.

Rate limits apply (`auth.forgot_password`, password-reset policies). Do not disable them for UAT.

### Option B — Reseed password hash

```bash
export UAT_SEED_PASSWORD='your-new-uat-password'
php bin/jobs.php uat:reset --confirm
php bin/jobs.php uat:seed
```

Reset removes UAT/demo-marked data and re-applies deterministic seed. Schema/migrations are preserved. Use only on designated UAT/local databases.

### Option C — Local admin bootstrap (local only)

`php bin/bootstrap-local-admin.php` is a **local** bootstrap aid, not the UAT persona mechanism. Do not use it to invent production admins.

---

## Safety rules

- Never seed or reset against staging/production (`EnvironmentCapability` refuses).
- Never commit real shared passwords or TOTP secrets.
- Never paste full email/SMS OTP or recovery codes into tickets.
- Finance persona must be used to **prove denial** of document routes, not to attempt workarounds.
