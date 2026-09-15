<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use DateTimeImmutable;

final class LearningQuestionResponseItemView
{
    public function __construct(
        public readonly int $responseId,
        public readonly string $body,
        public readonly string $responderName,
        public readonly DateTimeImmutable $respondedAt,
    ) {
    }
}
