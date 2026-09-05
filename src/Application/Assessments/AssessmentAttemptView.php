<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentAttempt;

final class AssessmentAttemptView
{
    /**
     * @param list<AssessmentAttemptQuestionView> $questions
     */
    public function __construct(
        public readonly AssessmentAttempt $attempt,
        public readonly Assessment $assessment,
        public readonly array $questions,
        public readonly bool $showResults,
    ) {
    }
}
