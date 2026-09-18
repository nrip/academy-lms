<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

interface LearnerLessonBookmarkRepository
{
    public function findForEnrolmentAndContent(int $enrolmentId, int $contentId): ?LearnerLessonBookmark;

    /**
     * @return list<LearnerLessonBookmark>
     */
    public function listForEnrolment(int $enrolmentId): array;

    public function upsert(int $enrolmentId, int $contentId, int $userId, DateTimeImmutable $at): int;

    public function deleteForEnrolmentAndContent(int $enrolmentId, int $contentId, int $userId): bool;
}
