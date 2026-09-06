<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

final class AssessmentAttemptStatus
{
    public const IN_PROGRESS = 'in_progress';
    public const SUBMITTED = 'submitted';
    public const TIMED_OUT = 'timed_out';

    /** @var list<string> */
    public const ALL = [
        self::IN_PROGRESS,
        self::SUBMITTED,
        self::TIMED_OUT,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid assessment attempt status.');
        }
    }
}
