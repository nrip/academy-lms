<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

interface LearnerLessonNoteRepository
{
    public function findForEnrolmentAndContent(int $enrolmentId, int $contentId): ?LearnerLessonNote;

    public function upsert(int $enrolmentId, int $contentId, int $userId, string $body, DateTimeImmutable $at): int;

    public function deleteForEnrolmentAndContent(int $enrolmentId, int $contentId, int $userId): bool;
}
