<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

final class EnrolmentAcademicStatus
{
    public const NOT_STARTED = 'not_started';
    public const IN_PROGRESS = 'in_progress';
    public const ACADEMICALLY_COMPLETED = 'academically_completed';
    public const PASSED = 'passed';
    public const NOT_PASSED = 'not_passed';

    /** @var list<string> */
    public const ALL = [
        self::NOT_STARTED,
        self::IN_PROGRESS,
        self::ACADEMICALLY_COMPLETED,
        self::PASSED,
        self::NOT_PASSED,
    ];

    public static function assertValid(?string $status): void
    {
        if ($status === null) {
            return;
        }
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Unknown enrolment academic status.');
        }
    }
}
