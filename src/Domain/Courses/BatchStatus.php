<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

final class BatchStatus
{
    public const PLANNED = 'planned';
    public const OPEN_FOR_APPLICATIONS = 'open_for_applications';
    public const OPEN_FOR_ENROLMENT = 'open_for_enrolment';
    public const FULL = 'full';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const ALL = [
        self::PLANNED,
        self::OPEN_FOR_APPLICATIONS,
        self::OPEN_FOR_ENROLMENT,
        self::FULL,
        self::IN_PROGRESS,
        self::COMPLETED,
        self::CANCELLED,
        self::ARCHIVED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid batch status.');
        }
    }
}
