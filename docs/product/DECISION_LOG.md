# Decision Log

| ID | Date | Decision | Status | Notes |
| --- | --- | --- | --- | --- |
| D1 | 2026-07-19 | Automated tests use PHPUnit. Constraint is an explicit reviewed semantic range compatible with PHP 8.4 at installation time; the Decision Log does not hard-code a PHPUnit major version. | Approved | Phase 0 |
| D2 | 2026-07-19 | Database migrations use Phinx, placed in Composer `require-dev`. | Approved | Phase 0 |
| D3 | 2026-07-19 | Coding standards: PHP-CS-Fixer; static analysis: PHPStan. | Approved | Phase 0 |
| D4 | 2026-07-19 | HTTP stack: Laminas Diactoros, Laminas HTTP Handler Runner, League Route, and PSR-15 contracts. No proprietary framework around them. Phase 0 uses `league/route` `^7.0` (stable) because `^5.1` conflicts with Phinx via `psr/simple-cache`. | Approved | Phase 0 |
| D5 | 2026-07-19 | PHP-DI is approved only for the composition root. Domain, Application, controller, service, and repository classes must not retrieve dependencies from the container. | Approved | Phase 0 |
| D6 | 2026-07-19 | `vlucas/phpdotenv` is approved for local development and CI only. Production must use environment variables or a secrets service directly. | Approved | Phase 0 |
| D7 | 2026-07-19 | Presentation uses plain PHP templates with central escaping helpers. | Approved | Phase 0 |
| D8 | 2026-07-19 | Frontend dependencies use npm with a committed `package-lock.json` and a controlled copy/build script. Phase 0 installs only Bootstrap and jQuery for the base layout. DataTables, Select2, and SweetAlert2 are loaded only by screens that need them (not in Phase 0). | Approved | Phase 0 |
| D9 | 2026-07-19 | Shared session and rate-limit store: configuration and contracts only in Phase 0. Implementation deferred until deployment architecture is approved. | Approved | Phase 0 |
| D10 | 2026-07-19 | PHP namespace root is `Academy\`. | Approved | Phase 0 |
| D11 | 2026-07-19 | Phase 0 configures and validates Phinx only. Production `audit_log` schema is a separate reviewed work package. | Approved | Phase 0 |
| DEP-1 | 2026-07-19 | Composer and npm dependencies must use explicit reviewed semantic-version constraints (no wildcards, `dev-main`, unbounded, or prerelease ranges). Commit `composer.lock` and `package-lock.json`. Run `composer audit` after install and resolve reported vulnerabilities before proceeding. | Approved | Phase 0 |
| ARCH-STATUS-1 | 2026-07-19 | Technical Architecture v1.1 status: “Approved for Phase 0 Foundation Build and Build Preparation. The broader functional build remains subject to the open decisions and conditional approvals identified in the SRS and Decision Log.” | Approved | Reconciles AGENTS.md and Architecture document |
| DOC-PATH-1 | 2026-07-19 | High-fidelity design artefacts live under `/docs/design/high-fidelity/` (not `hi-fidelity`). | Approved | Path naming |

## Phase 0 installed dependency versions (implementation)

Recorded at Phase 0 install time on `foundation/repository-scaffold`. Constraints remain the reviewed ranges in `composer.json` / `package.json`; lockfiles are authoritative.

### Composer (direct)

| Package | Installed version | Constraint |
| --- | --- | --- |
| laminas/laminas-diactoros | 3.8.0 | ^3.5 |
| laminas/laminas-httphandlerrunner | 2.13.0 | ^2.11 |
| league/route | 7.0.0 | ^7.0 |
| monolog/monolog | 3.10.0 | ^3.8 |
| php-di/php-di | 7.1.1 | ^7.0 |
| psr/container | 2.0.2 | ^2.0 |
| psr/http-server-handler | 1.0.2 | ^1.0 |
| psr/http-server-middleware | 1.0.2 | ^1.0 |
| psr/log | 3.0.2 | ^3.0 |
| vlucas/phpdotenv | 5.6.4 | ^5.6 |
| friendsofphp/php-cs-fixer | 3.95.15 | ^3.75 (require-dev) |
| phpstan/phpstan | 2.2.5 | ^2.1 (require-dev) |
| phpunit/phpunit | 11.5.56 | ^11.5 (require-dev) |
| robmorgan/phinx | 0.16.12 | ^0.16 (require-dev) |

### npm

| Package | Installed version | package.json pin |
| --- | --- | --- |
| bootstrap | 5.3.3 | 5.3.3 |
| jquery | 3.7.1 | 3.7.1 |

