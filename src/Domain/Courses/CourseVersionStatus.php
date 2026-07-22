<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

final class CourseVersionStatus
{
    public const DRAFT = 'draft';
    public const UNDER_REVIEW = 'under_review';
    public const PUBLISHED = 'published';
    public const ENROLMENT_CLOSED = 'enrolment_closed';
    public const UNPUBLISHED = 'unpublished';
    public const ARCHIVED = 'archived';
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ALL = [
        self::DRAFT,
        self::UNDER_REVIEW,
        self::PUBLISHED,
        self::ENROLMENT_CLOSED,
        self::UNPUBLISHED,
        self::ARCHIVED,
        self::CANCELLED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid course version status.');
        }
    }
}
