<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

final class CourseAdminScopeType
{
    public const COURSE = 'course';
    public const COURSE_VERSION = 'course_version';

    /** @var list<string> */
    public const ALL = [
        self::COURSE,
        self::COURSE_VERSION,
    ];

    public static function assertValid(string $scopeType): void
    {
        if (!in_array($scopeType, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid course admin scope type.');
        }
    }
}
