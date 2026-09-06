<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;

/**
 * CourseVersion publishing lifecycle (SRS REQ-CRS-2 statuses).
 * Phase 1 services use draft→published and draft→cancelled; full matrix is unit-tested.
 */
final class CourseVersionStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        CourseVersionStatus::DRAFT => [
            CourseVersionStatus::UNDER_REVIEW,
            CourseVersionStatus::PUBLISHED,
            CourseVersionStatus::CANCELLED,
        ],
        CourseVersionStatus::UNDER_REVIEW => [
            CourseVersionStatus::DRAFT,
            CourseVersionStatus::PUBLISHED,
            CourseVersionStatus::CANCELLED,
        ],
        CourseVersionStatus::PUBLISHED => [
            CourseVersionStatus::ENROLMENT_CLOSED,
            CourseVersionStatus::UNPUBLISHED,
            CourseVersionStatus::ARCHIVED,
        ],
        CourseVersionStatus::ENROLMENT_CLOSED => [
            CourseVersionStatus::UNPUBLISHED,
            CourseVersionStatus::ARCHIVED,
        ],
        CourseVersionStatus::UNPUBLISHED => [
            CourseVersionStatus::PUBLISHED,
            CourseVersionStatus::ARCHIVED,
            CourseVersionStatus::CANCELLED,
        ],
        CourseVersionStatus::ARCHIVED => [],
        CourseVersionStatus::CANCELLED => [],
    ];

    public function assertCanTransition(string $from, string $to): void
    {
        CourseVersionStatus::assertValid($from);
        CourseVersionStatus::assertValid($to);

        if ($from === $to) {
            throw new ConflictException('CourseVersion is already in the requested status.');
        }

        $allowed = self::ALLOWED[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new DomainRuleException(sprintf(
                'CourseVersion transition from %s to %s is not allowed.',
                $from,
                $to,
            ));
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function allowedPairs(): array
    {
        $pairs = [];
        foreach (self::ALLOWED as $from => $tos) {
            foreach ($tos as $to) {
                $pairs[] = [$from, $to];
            }
        }

        return $pairs;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function disallowedPairs(): array
    {
        $disallowed = [];
        foreach (CourseVersionStatus::ALL as $from) {
            foreach (CourseVersionStatus::ALL as $to) {
                if ($from === $to) {
                    continue;
                }
                if (!in_array($to, self::ALLOWED[$from] ?? [], true)) {
                    $disallowed[] = [$from, $to];
                }
            }
        }

        return $disallowed;
    }
}
