<?php

declare(strict_types=1);

# WP-01A operational notes (implementation artefacts — not product docs authority)

## Cleanup jobs

```bash
php bin/jobs.php session:cleanup
php bin/jobs.php rate-limit:cleanup
php bin/jobs.php outbox:relay   # no-ops / skips when OUTBOX_TRANSPORT=unconfigured
```

Suggested cron:
- session:cleanup every 5–15 minutes
- rate-limit:cleanup every 15–60 minutes
- outbox:relay only after a real transport is configured

## WP01-A review triggers (Decision Log)

Re-evaluate MySQL session/rate-limit stores (including possible Redis for rate limits) if:
- DB contention from session/rate-limit writes
- transactional latency impact
- session cleanup or lock contention across nodes
- failure to meet approved NFR/load-test thresholds

## Technical Lead merge gate

Merge of `slice/wp01a-security-audit-foundation` remains blocked until Technical Lead review of:
- MySQL atomic rate-limit behaviour
- session transaction boundaries
- CSRF storage and rotation
- failure matrix
- outbox lease recovery
- audit triggers
- scheduler lock SQL
- concurrency tests
