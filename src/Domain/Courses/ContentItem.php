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
        public readonly ?string $videoUrl,
        public readonly ?string $videoDeliveryMode,
        public readonly ?string $videoProvider,
        public readonly bool $mandatoryFlag,
        public readonly string $completionRule,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isVideo(): bool
    {
        return $this->contentType === ContentItemType::VIDEO;
    }
}
