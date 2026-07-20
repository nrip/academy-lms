<?php

declare(strict_types=1);

/**
 * Worker process for rate-limit concurrency test.
 * Prints the decision hit_count for a single increment.
 */

use Academy\Infrastructure\RateLimit\PdoRateLimitStore;
use Academy\Tests\Support\DatabaseTestCase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$bucketKey = $argv[1] ?? '';
if ($bucketKey === '') {
    fwrite(STDERR, "bucket key required\n");
    exit(1);
}

$store = new PdoRateLimitStore(DatabaseTestCase::connectionFactory());
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$result = $store->incrementAndGetCount(
    $bucketKey,
    'test.tight',
    $now,
    $now->modify('+60 seconds'),
);

echo (string) $result['hit_count'];
exit(0);
