<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class LearningQuestion
{
    public function __construct(
        public readonly int $questionId,
        public readonly int $enrolmentId,
        public readonly int $contentId,
        public readonly int $courseId,
        public readonly int $courseVersionId,
        public readonly int $batchId,
        public readonly int $moduleId,
        public readonly int $askedByUserId,
        public readonly string $body,
        public readonly string $status,
        public readonly DateTimeImmutable $askedAt,
        public readonly ?DateTimeImmutable $firstRespondedAt,
        public readonly ?DateTimeImmutable $closedAt,
        public readonly ?int $closedByUserId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === LearningQuestionStatus::OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === LearningQuestionStatus::CLOSED;
    }

    public function belongsToAsker(int $userId): bool
    {
        return $this->askedByUserId === $userId;
    }
}
