<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

final class ReviewerQueueFilter
{
    public const UNASSIGNED = 'unassigned';
    public const ASSIGNED_TO_ME = 'assigned_to_me';
    public const UNDER_REVIEW = 'under_review';
    public const RESUBMISSION_REQUESTED = 'resubmission_requested';
    public const READY_FOR_DECISION = 'ready_for_decision';
    public const RECENTLY_DECIDED = 'recently_decided';

    /** @var list<string> */
    public const ALL = [
        self::UNASSIGNED,
        self::ASSIGNED_TO_ME,
        self::UNDER_REVIEW,
        self::RESUBMISSION_REQUESTED,
        self::READY_FOR_DECISION,
        self::RECENTLY_DECIDED,
    ];

    public const DEFAULT = self::UNDER_REVIEW;

    public static function assertValid(string $filter): void
    {
        if (!in_array($filter, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid reviewer queue filter.');
        }
    }

    public static function normalize(?string $filter): string
    {
        if ($filter === null || trim($filter) === '') {
            return self::DEFAULT;
        }

        self::assertValid($filter);

        return $filter;
    }
}
