<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use Academy\Domain\Exception\ValidationException;

/**
 * Publish readiness for mcq_assessment content (used by CourseVersionPublishValidator / WP-L2).
 */
final class AssessmentPublishCompletenessValidator
{
    /**
     * @param list<AssessmentQuestionLink> $links
     * @return list<string> blocker messages (empty = ready)
     */
    public function blockers(?Assessment $assessment, array $links): array
    {
        $blockers = [];
        if ($assessment === null) {
            $blockers[] = 'MCQ assessment content requires a saved assessment configuration.';

            return $blockers;
        }

        if (count($links) < $assessment->questionsPerAttempt) {
            $blockers[] = sprintf(
                'Assessment "%s" needs at least %d linked question(s); %d linked.',
                $assessment->title,
                $assessment->questionsPerAttempt,
                count($links),
            );
        }

        return $blockers;
    }

    /**
     * @param list<AssessmentQuestionLink> $links
     */
    public function assertReady(?Assessment $assessment, array $links): void
    {
        $blockers = $this->blockers($assessment, $links);
        if ($blockers !== []) {
            throw new ValidationException(implode(' ', $blockers));
        }
    }
}
