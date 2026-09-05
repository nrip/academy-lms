<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class ContentProgress
{
    public function __construct(
        public readonly int $progressId,
        public readonly int $enrolmentId,
        public readonly int $contentId,
        public readonly string $completionStatus,
        public readonly ?string $resumePosition,
        public readonly ?string $watchPercentage,
        public readonly ?DateTimeImmutable $firstAccessedAt,
        public readonly ?DateTimeImmutable $lastAccessedAt,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly bool $manualOverrideFlag,
        public readonly ?string $completionSource,
        public readonly int $rowVersion,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->completionStatus === ContentProgressCompletionStatus::COMPLETED;
    }
}
