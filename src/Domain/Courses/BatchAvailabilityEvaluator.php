<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Deterministic Draft-selection eligibility (WP-02). Evaluates against `now` (UTC)
 * only — never reserves capacity. See docs/product/WP02_IMPLEMENTATION_NOTE.md
 * "Batch availability (deterministic)".
 */
final class BatchAvailabilityEvaluator
{
    public function evaluate(
        Course $course,
        CourseVersion $version,
        Batch $batch,
        ?DateTimeImmutable $now = null,
    ): BatchAvailability {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));

        if (!$course->isActive()) {
            return BatchAvailability::notSelectable(BatchAvailability::REASON_COURSE_NOT_ACTIVE);
        }

        if (!$version->isPublished() || !$version->isLocked()) {
            return BatchAvailability::notSelectable(BatchAvailability::REASON_VERSION_NOT_PUBLISHED);
        }

        if ($batch->status !== BatchStatus::OPEN_FOR_APPLICATIONS) {
            return BatchAvailability::notSelectable(BatchAvailability::REASON_BATCH_NOT_OPEN);
        }

        if ($now < $batch->applicationsOpenAt) {
            return BatchAvailability::notSelectable(BatchAvailability::REASON_BEFORE_WINDOW);
        }

        if ($now > $batch->applicationsCloseAt) {
            return BatchAvailability::notSelectable(BatchAvailability::REASON_AFTER_WINDOW);
        }

        return BatchAvailability::selectable();
    }
}
