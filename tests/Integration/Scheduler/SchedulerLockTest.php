<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Scheduler;

use Academy\Infrastructure\Scheduler\PdoSchedulerLock;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class SchedulerLockTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateWp01aTables();
    }

    public function testAcquireRenewRelease(): void
    {
        $lock = new PdoSchedulerLock(DatabaseTestCase::connectionFactory());
        self::assertTrue($lock->acquire('job_a', 'worker-1', 30));
        self::assertFalse($lock->acquire('job_a', 'worker-2', 30));
        self::assertTrue($lock->renew('job_a', 'worker-1', 30));
        self::assertFalse($lock->renew('job_a', 'worker-2', 30));
        $lock->release('job_a', 'worker-1');
        self::assertTrue($lock->acquire('job_a', 'worker-2', 30));
    }
}
