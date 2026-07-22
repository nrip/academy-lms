<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Database-backed temporary lockout: 5 failures within 15 minutes → lock 15 minutes.
 * Does not mutate account_status.
 */
final class LoginLockoutPolicy
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 15 * 60;
    public const LOCK_SECONDS = 15 * 60;

    /**
     * @param array{
     *   failed_login_count: int,
     *   failed_login_window_started_at: ?DateTimeImmutable,
     *   locked_until: ?DateTimeImmutable
     * } $state
     *
     * @return array{
     *   failed_login_count: int,
     *   failed_login_window_started_at: DateTimeImmutable,
     *   locked_until: ?DateTimeImmutable,
     *   last_failed_login_at: DateTimeImmutable,
     *   newly_locked: bool
     * }
     */
    public static function recordFailure(array $state, DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));

        if ($state['locked_until'] !== null && $state['locked_until'] > $now) {
            return [
                'failed_login_count' => $state['failed_login_count'],
                'failed_login_window_started_at' => $state['failed_login_window_started_at'] ?? $now,
                'locked_until' => $state['locked_until'],
                'last_failed_login_at' => $now,
                'newly_locked' => false,
            ];
        }

        $windowStart = $state['failed_login_window_started_at'];
        $count = $state['failed_login_count'];

        if ($windowStart === null || $now->getTimestamp() - $windowStart->getTimestamp() >= self::WINDOW_SECONDS) {
            $windowStart = $now;
            $count = 1;
        } else {
            $count++;
        }

        $newlyLocked = false;
        $lockedUntil = null;
        if ($count >= self::MAX_FAILURES) {
            $lockedUntil = $now->modify('+' . self::LOCK_SECONDS . ' seconds');
            $newlyLocked = true;
        }

        return [
            'failed_login_count' => $count,
            'failed_login_window_started_at' => $windowStart,
            'locked_until' => $lockedUntil,
            'last_failed_login_at' => $now,
            'newly_locked' => $newlyLocked,
        ];
    }

    /**
     * @return array{
     *   failed_login_count: int,
     *   failed_login_window_started_at: null,
     *   locked_until: null,
     *   last_failed_login_at: null,
     *   last_login_at: DateTimeImmutable
     * }
     */
    public static function recordSuccess(DateTimeImmutable $now): array
    {
        return [
            'failed_login_count' => 0,
            'failed_login_window_started_at' => null,
            'locked_until' => null,
            'last_failed_login_at' => null,
            'last_login_at' => $now->setTimezone(new DateTimeZone('UTC')),
        ];
    }

    public static function isLocked(?DateTimeImmutable $lockedUntil, DateTimeImmutable $now): bool
    {
        return $lockedUntil !== null && $lockedUntil > $now->setTimezone(new DateTimeZone('UTC'));
    }
}
