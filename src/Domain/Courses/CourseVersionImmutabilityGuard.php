<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ConflictException;

/**
 * Application-layer guard mirroring the DB trigger defence-in-depth (Rule 5 / AGENTS.md §5.3).
 * The trigger is the last line of defence; this guard exists so the application can
 * return a clear 409 with a "create Version N+1" message instead of a raw SQL exception.
 */
final class CourseVersionImmutabilityGuard
{
    /**
     * @throws ConflictException when the version is locked
     */
    public function assertMutable(CourseVersion $version): void
    {
        if ($version->isLocked()) {
            throw new ConflictException(
                'This CourseVersion is locked and immutable. Create Version N+1 to make changes.',
            );
        }
    }
}
