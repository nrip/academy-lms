<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

final class EligibilityRule
{
    public function __construct(
        public readonly int $ruleId,
        public readonly int $courseVersionId,
        public readonly string $field,
        public readonly string $operator,
        public readonly string $value,
        public readonly string $logicGroup,
        public readonly string $displayLabel,
        public readonly int $sortOrder,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
