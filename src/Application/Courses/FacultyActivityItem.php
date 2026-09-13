<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use DateTimeImmutable;

final class FacultyActivityItem
{
    public function __construct(
        public readonly string $label,
        public readonly DateTimeImmutable $at,
        public readonly string $href,
    ) {
    }
}
