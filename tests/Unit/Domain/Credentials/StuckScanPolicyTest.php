<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Credentials;

use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\StuckScanPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class StuckScanPolicyTest extends TestCase
{
    public function testNotStuckWhenScanStatusIsNotPending(): void
    {
        $policy = new StuckScanPolicy(slaSeconds: 900);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $queuedAt = $now->modify('-2000 seconds');

        self::assertFalse($policy->isStuck($now, $queuedAt, DocumentScanStatus::CLEAN));
    }

    public function testNotStuckWhenQueuedAtIsNull(): void
    {
        $policy = new StuckScanPolicy(slaSeconds: 900);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertFalse($policy->isStuck($now, null, DocumentScanStatus::PENDING));
    }

    public function testNotStuckWithinSla(): void
    {
        $policy = new StuckScanPolicy(slaSeconds: 900);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $queuedAt = $now->modify('-500 seconds');

        self::assertFalse($policy->isStuck($now, $queuedAt, DocumentScanStatus::PENDING));
    }

    public function testStuckOnceSlaElapsed(): void
    {
        $policy = new StuckScanPolicy(slaSeconds: 900);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $queuedAt = $now->modify('-901 seconds');

        self::assertTrue($policy->isStuck($now, $queuedAt, DocumentScanStatus::PENDING));
    }

    public function testStuckExactlyAtSlaBoundary(): void
    {
        $policy = new StuckScanPolicy(slaSeconds: 900);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $queuedAt = $now->modify('-900 seconds');

        self::assertTrue($policy->isStuck($now, $queuedAt, DocumentScanStatus::PENDING));
    }

    public function testRetriesExhaustedAtOrAboveMaxAttempts(): void
    {
        $policy = new StuckScanPolicy(maxAttempts: 5);

        self::assertFalse($policy->retriesExhausted(4));
        self::assertTrue($policy->retriesExhausted(5));
        self::assertTrue($policy->retriesExhausted(6));
    }

    public function testNextRetryAtUsesExponentialBackoffCappedAtMax(): void
    {
        $policy = new StuckScanPolicy(backoffBaseSeconds: 60, backoffCapSeconds: 900);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertSame(60, $policy->nextRetryAt($now, 1)->getTimestamp() - $now->getTimestamp());
        self::assertSame(120, $policy->nextRetryAt($now, 2)->getTimestamp() - $now->getTimestamp());
        self::assertSame(240, $policy->nextRetryAt($now, 3)->getTimestamp() - $now->getTimestamp());
        self::assertSame(900, $policy->nextRetryAt($now, 20)->getTimestamp() - $now->getTimestamp());
    }

    public function testAccessors(): void
    {
        $policy = new StuckScanPolicy(slaSeconds: 300, maxAttempts: 3);

        self::assertSame(300, $policy->slaSeconds());
        self::assertSame(3, $policy->maxAttempts());
    }
}
