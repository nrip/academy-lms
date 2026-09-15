<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class LearningQuestionResponse
{
    public function __construct(
        public readonly int $responseId,
        public readonly int $questionId,
        public readonly int $respondedByUserId,
        public readonly string $body,
        public readonly DateTimeImmutable $respondedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
