<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

final class ReviewerScopeType
{
    public const COURSE = 'course';
    public const COURSE_VERSION = 'course_version';
    public const BATCH = 'batch';

    /** @var list<string> */
    public const ALL = [
        self::COURSE,
        self::COURSE_VERSION,
        self::BATCH,
    ];

    public static function assertValid(string $scopeType): void
    {
        if (!in_array($scopeType, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid reviewer scope type.');
        }
    }
}
