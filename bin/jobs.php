<?php

declare(strict_types=1);

/**
 * CLI entry for WP-01A / WP-01B-2a operational jobs.
 *
 * Usage:
 *   php bin/jobs.php session:cleanup
 *   php bin/jobs.php rate-limit:cleanup
 *   php bin/jobs.php outbox:relay
 *   php bin/jobs.php notification:deliver
 *   php bin/jobs.php token-confirmation:cleanup
 *   php bin/jobs.php document:scan
 *   php bin/jobs.php document:stuck-scan
 *   php bin/jobs.php payment:webhook-process
 *   php bin/jobs.php payment:reconcile
 */

use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\StuckScanWatchService;
use Academy\Application\Identity\TokenConfirmationCleanupService;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Application\Outbox\OutboxRelayService;
use Academy\Application\Payments\PaymentReconciliationService;
use Academy\Application\Payments\PaymentWebhookProcessor;
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
    'notification:deliver' => (static function () use ($container, $workerId): int {
        $processed = $container->get(IdentityNotificationDeliveryWorker::class)->run($workerId);
        fwrite(STDOUT, "notification:deliver processed={$processed}\n");

        return 0;
    })(),
    'token-confirmation:cleanup' => (static function () use ($container, $lock, $workerId): int {
        if (!$lock->acquire('token_confirmation_cleanup', $workerId, 120)) {
            fwrite(STDERR, "Could not acquire token_confirmation_cleanup lock\n");

            return 1;
        }
        try {
            $deleted = $container->get(TokenConfirmationCleanupService::class)->run();
            fwrite(STDOUT, "token-confirmation:cleanup deleted={$deleted}\n");

            return 0;
        } finally {
            $lock->release('token_confirmation_cleanup', $workerId);
        }
    })(),
    'document:scan' => (static function () use ($container, $workerId): int {
        $processed = $container->get(DocumentScanWorker::class)->run($workerId);
        fwrite(STDOUT, "document:scan processed={$processed}\n");

        return 0;
    })(),
    'document:stuck-scan' => (static function () use ($container, $lock, $workerId): int {
        if (!$lock->acquire('document_stuck_scan', $workerId, 120)) {
            fwrite(STDERR, "Could not acquire document_stuck_scan lock\n");

            return 1;
        }
        try {
            $handled = $container->get(StuckScanWatchService::class)->run();
            fwrite(STDOUT, "document:stuck-scan handled={$handled}\n");

            return 0;
        } finally {
            $lock->release('document_stuck_scan', $workerId);
        }
    })(),
    'payment:webhook-process' => (static function () use ($container, $workerId): int {
        $processed = $container->get(PaymentWebhookProcessor::class)->run($workerId);
        fwrite(STDOUT, "payment:webhook-process processed={$processed}\n");

        return 0;
    })(),
    'payment:reconcile' => (static function () use ($container, $workerId): int {
        $processed = $container->get(PaymentReconciliationService::class)->run($workerId);
        fwrite(STDOUT, "payment:reconcile processed={$processed}\n");

        return 0;
    })(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown command: {$command}\n");
        fwrite(STDERR, "Commands: session:cleanup | rate-limit:cleanup | outbox:relay | notification:deliver | token-confirmation:cleanup | document:scan | document:stuck-scan | payment:webhook-process | payment:reconcile\n");

        return 1;
    })(),
};

exit($exit);
