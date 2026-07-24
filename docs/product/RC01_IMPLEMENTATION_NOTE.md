# RC-01 — UAT Release and Deployment Hardening

**Package ID:** RC-01  
**Branch:** `hardening/rc01-uat-deployment`  
**Authority:** Vertical-slice merge complete (WP-01…WP-07); PRODUCTION_READINESS_REGISTER; AGENTS.md §8–11.  
**Nature:** Operational hardening — **not** a feature package. No new admissions/payment/player/assessment/certificate scope.

## Objective

Turn the merged Mode A vertical slice into a reproducible, testable UAT release candidate that another technical team can install, seed, operate, monitor, and hand off without tribal knowledge.

## Decisions (no blockers)

1. **Package identifier:** No prior RC package existed in the roadmap. Adopted **RC-01 — UAT Release and Deployment Hardening** as the release-candidate label; roadmap still ends at WP-07 for feature delivery.
2. **Environment modes:** Central `EnvironmentCapability` for `local | testing | ci | uat | staging | production`. **`uat` is not production-like** for fake-adapter policy: fake/local adapters require explicit flags. Soft secret defaults remain `local|testing|ci` only — UAT must set key material explicitly.
3. **Dotenv:** Load `.env` for `local|testing|ci|uat` only. Staging/production remain fail-closed without dotenv file load.
4. **CLI convention:** Keep `php bin/jobs.php …`. Add `php bin/setup.php` for clean-install verification; add `uat:seed` / `uat:reset` jobs.
5. **Health:** Keep `GET /health` as liveness alias; add `GET /health/live` and `GET /health/ready`. Build metadata is optional on readiness only (never secrets/paths).
6. **UAT seed:** Deterministic personas + catalogue + representative Mode A states; gated to `local|testing|ci|uat`; never production/staging.
7. **CI:** Pin Node 22 (LTS); add concurrency cancellation, timeouts, and a CI-safe fresh-migrate/seed smoke; observe flaky concurrency test (no silent auto-retry).

## Genuine gaps addressed

| Gap | Disposition |
|---|---|
| No EnvValidator / capability matrix | Implemented |
| No setup/bootstrap one-shot | `bin/setup.php` + composer scripts |
| No orchestrated UAT seed/reset | `uat:seed` / `uat:reset --confirm` |
| `/health` only | live + ready |
| Incomplete `.env.example` | Expanded (placeholder secrets only) |
| Missing UAT/ops/release docs | Added under `docs/uat`, `docs/operations`, `docs/releases`, `docs/engineering` |
| PR register unclassified | Triaged by UAT / pilot / production / future |
| Backup/restore untested | Scripted UAT rehearsal + runbook |
| Node 20 deprecation in CI | Move to Node 22 if `npm ci` passes |

## Out of scope (unchanged)

Course player, content authoring, assessments, certificates, refund automation, production AWS/S3/SES/SMS packs, marketing notifications, new SM states.

## Escalations

None. Fake-provider policy for `uat` is deliberate and documented; production/staging remain fail-closed.

## Validation (final)

| Gate | Result |
|---|---|
| Full PHPUnit | **1122 tests / 3055 assertions** |
| PHPStan | Pass (0 errors) |
| php-cs-fixer dry-run | Pass (0 files) |
| composer validate --strict | Pass |
| composer audit | No advisories (cache; network limited in local run) |
| UAT seed ×2 idempotency | Pass |
| UAT reset + reseed | Pass |
| `bin/setup.php --skip-assets --skip-migrate` | Pass (readiness OK) |
| Backup/restore rehearsal | Pass (13 phinx rows; 0 orphan enrolments) |
| `git diff --check` | Pass |

## Known limitations

1. UAT scenario applications use controlled fixture inserts for list/detail states; interactive `learner@uat.example.test` has no conflicting application so Mode A journeys use real services/SMs.
2. Backup rehearsal is local `mysqldump` only — does not claim production DR readiness (`PR-BACKUP`).
3. Node moved to 22 for CI; `package.json` engines `>=22`.
4. `git pull origin main` could not refresh in this environment (DNS); branch started from local `main` at `c88ba61` (already tracking origin/main).
