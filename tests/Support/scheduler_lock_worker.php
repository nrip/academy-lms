<?php

declare(strict_types=1);

/**
 * Worker process for scheduler-lock concurrency test.
 *
 * argv[1] = lock_name
 * argv[2] = locked_by
 * argv[3] = ttl_seconds
 * argv[4] = action: acquire|renew|release
 *
 * Prints "1" on success, "0" on failure.
 */

use Academy\Infrastructure\Scheduler\PdoSchedulerLock;
use Academy\Tests\Support\DatabaseTestCase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$lockName = $argv[1] ?? '';
$lockedBy = $argv[2] ?? '';
$ttl = (int) ($argv[3] ?? 30);
$action = $argv[4] ?? 'acquire';

if ($lockName === '' || $lockedBy === '') {
    fwrite(STDERR, "lock_name and locked_by required\n");
    exit(1);
}

$lock = new PdoSchedulerLock(DatabaseTestCase::connectionFactory());

$result = match ($action) {
    'acquire' => $lock->acquire($lockName, $lockedBy, $ttl),
    'renew' => $lock->renew($lockName, $lockedBy, $ttl),
    'release' => $lock->release($lockName, $lockedBy),
    default => throw new InvalidArgumentException('Unknown action: ' . $action),
};

echo $result ? '1' : '0';
exit(0);
