<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class LearnerLessonBookmark
{
    public function __construct(
        public readonly int $bookmarkId,
        public readonly int $enrolmentId,
        public readonly int $contentId,
        public readonly int $userId,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
