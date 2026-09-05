<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

interface AssessmentRepository
{
    public function findById(int $assessmentId): ?Assessment;

    public function findByContentId(int $contentId): ?Assessment;

    /**
     * @param array{
     *   content_id: int,
     *   title: string,
     *   questions_per_attempt: int,
     *   pass_threshold_percent: string,
     *   time_limit_seconds: ?int,
     *   max_attempts: int,
     *   cooldown_seconds: ?int,
     *   randomise_questions: bool,
     *   randomise_options: bool
     * } $data
     */
    public function insert(array $data): int;

    /**
     * @param array{
     *   title: string,
     *   questions_per_attempt: int,
     *   pass_threshold_percent: string,
     *   time_limit_seconds: ?int,
     *   max_attempts: int,
     *   cooldown_seconds: ?int,
     *   randomise_questions: bool,
     *   randomise_options: bool
     * } $data
     */
    public function update(int $assessmentId, array $data): bool;
}
