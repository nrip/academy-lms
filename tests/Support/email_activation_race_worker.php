<?php

declare(strict_types=1);

/**
 * Concurrent activation-race worker: directly races UserWriteRepository::applyEmailVerification
 * for the same user_id to prove the underlying activation transition is idempotent and
 * race-safe (SELECT ... FOR UPDATE) even when two processes attempt it at the same instant.
 * Args: user_id
 * Prints: activated:<0|1> | error:<class>
 */

use Academy\Domain\Identity\UserWriteRepository;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId < 1) {
    fwrite(STDERR, "usage: email_activation_race_worker.php <user_id>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var UserWriteRepository $users */
    $users = $container->get(UserWriteRepository::class);
    /** @var TransactionManager $transactions */
    $transactions = $container->get(TransactionManager::class);
    // applyEmailVerification()'s internal SELECT ... FOR UPDATE only holds a real row lock
    // inside an explicit transaction (same as every production caller e.g.
    // TokenConfirmationService::confirm()); calling it directly under autocommit would let
    // the lock release instantly, defeating the very race this worker proves is safe.
    $result = $transactions->run(function (\PDO $pdo) use ($users, $userId): array {
        unset($pdo);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $users->applyEmailVerification($userId, $now);
    });
    echo 'activated:' . ($result['activated'] ? '1' : '0');
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
