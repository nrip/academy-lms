<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use DateTimeImmutable;

final class LearningQuestionThreadItemView
{
    /**
     * @param list<LearningQuestionResponseItemView> $responses
     */
    public function __construct(
        public readonly int $questionId,
        public readonly string $body,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly DateTimeImmutable $askedAt,
        public readonly bool $canClose,
        public readonly array $responses,
    ) {
    }
}
