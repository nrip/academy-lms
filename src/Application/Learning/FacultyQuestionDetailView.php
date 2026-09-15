<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use DateTimeImmutable;

final class FacultyQuestionDetailView
{
    /**
     * @param list<LearningQuestionResponseItemView> $responses
     */
    public function __construct(
        public readonly int $questionId,
        public readonly string $courseTitle,
        public readonly string $chapterTitle,
        public readonly string $lessonTitle,
        public readonly string $learnerName,
        public readonly string $body,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly DateTimeImmutable $askedAt,
        public readonly bool $canRespond,
        public readonly bool $canClose,
        public readonly array $responses,
    ) {
    }
}
