<?php

declare(strict_types=1);

/**
 * CLI entry for operational jobs (WP-01A … RC-01).
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
 *   php bin/jobs.php uat:seed
 *   php bin/jobs.php uat:reset --confirm
 */

use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\StuckScanWatchService;
use Academy\Application\Identity\TokenConfirmationCleanupService;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Application\Ops\DemoPrepareService;
use Academy\Application\Ops\DemoProcessService;
use Academy\Application\Ops\UatResetService;
use Academy\Application\Ops\UatSeedService;
use Academy\Application\Outbox\OutboxRelayService;
use Academy\Application\Payments\DemoPaymentSimulationService;
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
$confirm = in_array('--confirm', $argv, true);
$migrate = in_array('--migrate', $argv, true);
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
        $identity = $container->get(IdentityNotificationDeliveryWorker::class)->run($workerId);
        $transactional = $container->get(TransactionalNotificationDeliveryWorker::class)->run($workerId);
        fwrite(STDOUT, "notification:deliver identity={$identity} transactional={$transactional}\n");

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
    'uat:seed' => (static function () use ($container): int {
        try {
            $result = $container->get(UatSeedService::class)->seed();
        } catch (Throwable $e) {
            fwrite(STDERR, 'uat:seed failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        fwrite(STDOUT, 'uat:seed personas=' . $result['personas']
            . ' catalogue=' . ($result['catalogue'] ? 'yes' : 'no')
            . ' applications=' . $result['applications']
            . ' notifications=' . $result['notifications'] . "\n");
        foreach ($result['summary'] as $line) {
            fwrite(STDOUT, '  ' . $line . "\n");
        }

        return $result['catalogue'] ? 0 : 2;
    })(),
    'uat:reset' => (static function () use ($container, $confirm): int {
        try {
            $result = $container->get(UatResetService::class)->reset($confirm);
        } catch (Throwable $e) {
            fwrite(STDERR, 'uat:reset failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        fwrite(STDOUT, 'uat:reset deleted_users=' . $result['deleted_users']
            . ' deleted_applications=' . $result['deleted_applications']
            . ' deleted_notifications=' . $result['deleted_notifications'] . "\n");
        foreach ($result['summary'] as $line) {
            fwrite(STDOUT, '  ' . $line . "\n");
        }

        return 0;
    })(),
    'demo:prepare' => (static function () use ($container, $confirm, $migrate): int {
        try {
            $result = $container->get(DemoPrepareService::class)->prepare($confirm, $migrate);
        } catch (Throwable $e) {
            fwrite(STDERR, 'demo:prepare failed: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite(STDOUT, "demo:prepare ok\n");
        fwrite(STDOUT, '  app_url=' . $result['app_url'] . "\n");
        fwrite(STDOUT, '  personas=' . $result['personas']
            . ' applications=' . $result['applications']
            . ' notifications=' . $result['notifications'] . "\n");
        fwrite(STDOUT, '  password_source=' . $result['password_source'] . "\n");
        fwrite(STDOUT, '  demo_password=' . $result['password'] . "\n");
        foreach ($result['credentials'] as $cred) {
            fwrite(STDOUT, '  login ' . $cred['persona'] . ': ' . $cred['email']
                . ' → ' . $result['app_url'] . $cred['landing'] . "\n");
        }
        fwrite(STDOUT, "  next: php -S 127.0.0.1:8080 -t public\n");
        fwrite(STDOUT, "  then: php bin/jobs.php demo:process  (after demo payment / uploads)\n");
        foreach ($result['summary'] as $line) {
            fwrite(STDOUT, '  ' . $line . "\n");
        }

        return 0;
    })(),
    'demo:process' => (static function () use ($container, $workerId): int {
        try {
            $result = $container->get(DemoProcessService::class)->process($workerId);
        } catch (Throwable $e) {
            fwrite(STDERR, 'demo:process failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        fwrite(STDOUT, "demo:process ok worker={$result['worker_id']}\n");
        foreach ($result['steps'] as $step) {
            fwrite(STDOUT, '  ' . $step['step'] . '=' . $step['processed'] . "\n");
        }

        return 0;
    })(),
    'demo:payment-capture' => (static function () use ($container, $argv): int {
        $paymentId = (int) ($argv[2] ?? 0);
        if ($paymentId < 1) {
            fwrite(STDERR, "Usage: php bin/jobs.php demo:payment-capture {paymentId}\n");

            return 1;
        }
        try {
            $result = $container->get(DemoPaymentSimulationService::class)
                ->simulateCliCapture($paymentId, true, 'demo-cli:' . getmypid());
        } catch (Throwable $e) {
            fwrite(STDERR, 'demo:payment-capture failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        fwrite(STDOUT, 'demo:payment-capture payment_id=' . $result['payment_id']
            . ' webhook_event_id=' . $result['webhook_event_id']
            . ' duplicate=' . ($result['duplicate'] ? 'yes' : 'no')
            . ' processed=' . $result['processed'] . "\n");

        return 0;
    })(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown command: {$command}\n");
        fwrite(STDERR, "Commands: session:cleanup | rate-limit:cleanup | outbox:relay | notification:deliver | token-confirmation:cleanup | document:scan | document:stuck-scan | payment:webhook-process | payment:reconcile | uat:seed | uat:reset --confirm | demo:prepare --confirm [--migrate] | demo:process | demo:payment-capture {paymentId}\n");

        return 1;
    })(),
};

exit($exit);
