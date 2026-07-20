<?php

declare(strict_types=1);

/**
 * CLI entry for WP-01A operational jobs.
 *
 * Usage:
 *   php bin/jobs.php session:cleanup
 *   php bin/jobs.php rate-limit:cleanup
 *   php bin/jobs.php outbox:relay
 */

use Academy\Application\Outbox\OutboxRelayService;
use Academy\Domain\Security\RateLimitStore;
use Academy\Domain\Security\SessionRepository;
use Academy\Infrastructure\Scheduler\PdoSchedulerLock;
use Psr\Container\ContainerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var ContainerInterface $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$command = $argv[1] ?? '';
$workerId = gethostname() . ':' . getmypid();

$lock = $container->get(PdoSchedulerLock::class);

$exit = match ($command) {
    'session:cleanup' => (static function () use ($container, $lock, $workerId): int {
        if (!$lock->acquire('session_cleanup', $workerId, 120)) {
            fwrite(STDERR, "Could not acquire session_cleanup lock\n");

            return 1;
        }
        try {
            $deleted = $container->get(SessionRepository::class)->deleteExpired(
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            fwrite(STDOUT, "session:cleanup deleted={$deleted}\n");

            return 0;
        } finally {
            $lock->release('session_cleanup', $workerId);
        }
    })(),
    'rate-limit:cleanup' => (static function () use ($container, $lock, $workerId): int {
        if (!$lock->acquire('rate_limit_cleanup', $workerId, 120)) {
            fwrite(STDERR, "Could not acquire rate_limit_cleanup lock\n");

            return 1;
        }
        try {
            $deleted = $container->get(RateLimitStore::class)->deleteExpired(
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            fwrite(STDOUT, "rate-limit:cleanup deleted={$deleted}\n");

            return 0;
        } finally {
            $lock->release('rate_limit_cleanup', $workerId);
        }
    })(),
    'outbox:relay' => (static function () use ($container, $workerId): int {
        $relay = $container->get(OutboxRelayService::class);
        if (!$relay->transportConfigured()) {
            fwrite(STDERR, "outbox:relay skipped — transport not configured\n");

            return 0;
        }
        $processed = $relay->run($workerId);
        fwrite(STDOUT, "outbox:relay processed={$processed}\n");

        return 0;
    })(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown command: {$command}\n");
        fwrite(STDERR, "Commands: session:cleanup | rate-limit:cleanup | outbox:relay\n");

        return 1;
    })(),
};

exit($exit);
