<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Identity\LoginLockoutPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class LoginLockoutPolicyTest extends TestCase
{
    public function testFiveFailuresWithinWindowLockAccount(): void
    {
        $now = new DateTimeImmutable('2026-07-22 12:00:00.000000', new DateTimeZone('UTC'));
        $state = [
            'failed_login_count' => 0,
            'failed_login_window_started_at' => null,
            'locked_until' => null,
        ];

        for ($i = 1; $i <= 4; ++$i) {
            $state = LoginLockoutPolicy::recordFailure($state, $now);
            self::assertFalse($state['newly_locked']);
            self::assertNull($state['locked_until']);
            self::assertSame($i, $state['failed_login_count']);
        }

        $state = LoginLockoutPolicy::recordFailure($state, $now);
        self::assertTrue($state['newly_locked']);
        self::assertSame(5, $state['failed_login_count']);
        self::assertNotNull($state['locked_until']);
        self::assertSame(
            $now->modify('+15 minutes')->format('Y-m-d H:i:s.u'),
            $state['locked_until']->format('Y-m-d H:i:s.u'),
        );
    }

    public function testWindowExpiryResetsCount(): void
    {
        $start = new DateTimeImmutable('2026-07-22 12:00:00.000000', new DateTimeZone('UTC'));
        $state = LoginLockoutPolicy::recordFailure([
            'failed_login_count' => 0,
            'failed_login_window_started_at' => null,
            'locked_until' => null,
        ], $start);
        self::assertSame(1, $state['failed_login_count']);

        $later = $start->modify('+16 minutes');
        $state = LoginLockoutPolicy::recordFailure($state, $later);
        self::assertSame(1, $state['failed_login_count']);
        self::assertSame($later->format('Y-m-d H:i:s.u'), $state['failed_login_window_started_at']->format('Y-m-d H:i:s.u'));
    }

    public function testSuccessfulLoginClearsCounters(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cleared = LoginLockoutPolicy::recordSuccess($now);
        self::assertSame(0, $cleared['failed_login_count']);
        self::assertNull($cleared['failed_login_window_started_at']);
        self::assertNull($cleared['locked_until']);
        self::assertNull($cleared['last_failed_login_at']);
        self::assertSame($now->format('Y-m-d H:i:s.u'), $cleared['last_login_at']->format('Y-m-d H:i:s.u'));
    }

    public function testIsLockedRespectsExpiry(): void
    {
        $now = new DateTimeImmutable('2026-07-22 12:00:00.000000', new DateTimeZone('UTC'));
        self::assertTrue(LoginLockoutPolicy::isLocked($now->modify('+1 second'), $now));
        self::assertFalse(LoginLockoutPolicy::isLocked($now->modify('-1 second'), $now));
        self::assertFalse(LoginLockoutPolicy::isLocked(null, $now));
    }
}
