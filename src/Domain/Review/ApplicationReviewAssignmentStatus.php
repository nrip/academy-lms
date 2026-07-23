<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

final class ApplicationReviewAssignmentStatus
{
    public const ACTIVE = 'active';
    public const RELEASED = 'released';
    public const COMPLETED = 'completed';

    /** @var list<string> */
    public const ALL = [
        self::ACTIVE,
        self::RELEASED,
        self::COMPLETED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid application review assignment status.');
        }
    }
}
