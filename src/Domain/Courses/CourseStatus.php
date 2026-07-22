<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

final class CourseStatus
{
    public const ACTIVE = 'active';
    public const RETIRED = 'retired';

    /** @var list<string> */
    public const ALL = [
        self::ACTIVE,
        self::RETIRED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid course status.');
        }
    }
}
