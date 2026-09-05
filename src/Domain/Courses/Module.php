<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

final class Module
{
    public function __construct(
        public readonly int $moduleId,
        public readonly int $courseVersionId,
        public readonly int $sequence,
        public readonly string $title,
        public readonly string $description,
        public readonly bool $mandatoryFlag,
        public readonly string $releaseRule,
        public readonly ?int $prerequisiteModuleId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
