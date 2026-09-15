<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

interface LearningQuestionRepository
{
    /**
     * @param array{
     *   enrolment_id: int,
     *   content_id: int,
     *   course_id: int,
     *   course_version_id: int,
     *   batch_id: int,
     *   module_id: int,
     *   asked_by_user_id: int,
     *   body: string,
     *   asked_at: DateTimeImmutable
     * } $data
     */
    public function insert(array $data): int;

    public function findById(int $questionId): ?LearningQuestion;

    /**
     * @return list<LearningQuestion>
     */
    public function listForEnrolmentAndContent(int $enrolmentId, int $contentId): array;

    /**
     * @param list<int> $courseIds
     * @return list<LearningQuestion>
     */
    public function listOpenForCourses(array $courseIds, int $limit = 50): array;

    /**
     * @param list<int> $courseIds
     * @return list<LearningQuestion>
     */
    public function listForCourses(array $courseIds, int $limit = 50): array;

    public function markAnswered(int $questionId, DateTimeImmutable $at): bool;

    public function markClosed(int $questionId, int $closedByUserId, DateTimeImmutable $at): bool;
}
