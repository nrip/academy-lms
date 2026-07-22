<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Mirrors the `chk_batches_dates` / `chk_batches_capacity` DB constraints so
 * invalid Batch data is rejected with field-level errors before it reaches SQL.
 */
final class BatchDateValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $applicationsOpenAt,
        DateTimeImmutable $applicationsCloseAt,
        int $minCapacity,
        int $maxCapacity,
    ): void {
        $fields = [];

        if ($endsAt < $startsAt) {
            $fields['ends_at'] = ['End date must not be before the start date.'];
        }

        if ($applicationsCloseAt < $applicationsOpenAt) {
            $fields['applications_close_at'] = ['Applications close date must not be before the open date.'];
        }

        if ($minCapacity < 0) {
            $fields['min_capacity'] = ['Minimum capacity must not be negative.'];
        }

        if ($maxCapacity < $minCapacity) {
            $fields['max_capacity'] = ['Maximum capacity must not be less than minimum capacity.'];
        }

        if ($fields !== []) {
            throw new ValidationException('Batch dates or capacity are invalid.', $fields);
        }
    }
}
