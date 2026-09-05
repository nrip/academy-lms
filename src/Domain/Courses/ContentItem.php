<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

final class ContentItem
{
    public function __construct(
        public readonly int $contentId,
        public readonly int $moduleId,
        public readonly int $sequence,
        public readonly string $contentType,
        public readonly string $title,
        public readonly ?string $bodyText,
        public readonly ?string $objectKey,
        public readonly bool $mandatoryFlag,
        public readonly string $completionRule,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
