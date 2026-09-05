<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Domain\Assessments\AssessmentAttemptQuestion;

final class AssessmentAttemptQuestionView
{
    /**
     * @param list<int> $revealCorrectOptionIds
     */
    public function __construct(
        public readonly AssessmentAttemptQuestion $question,
        public readonly ?int $selectedOptionId,
        public readonly ?bool $isCorrect,
        public readonly ?string $marksAwarded,
        public readonly array $revealCorrectOptionIds,
    ) {
    }
}
