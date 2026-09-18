<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class LearnerLessonNote
{
    public function __construct(
        public readonly int $noteId,
        public readonly int $enrolmentId,
        public readonly int $contentId,
        public readonly int $userId,
        public readonly string $body,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
