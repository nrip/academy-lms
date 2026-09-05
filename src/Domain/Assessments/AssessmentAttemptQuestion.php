<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

/**
 * Frozen question paper row for an attempt (options_json excludes correctness for clients).
 */
final class AssessmentAttemptQuestion
{
    /**
     * @param list<array{option_id: int, sequence: int, option_text: string}> $options
     * @param list<int> $correctOptionIds
     */
    public function __construct(
        public readonly int $attemptQuestionId,
        public readonly int $attemptId,
        public readonly int $questionId,
        public readonly int $questionVersion,
        public readonly int $sequence,
        public readonly string $stem,
        public readonly string $marks,
        public readonly array $options,
        public readonly array $correctOptionIds,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
