<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

/**
 * Scores an attempt from frozen snapshots + selected responses (not live bank).
 */
final class AttemptScoringService
{
    /**
     * @param list<AssessmentAttemptQuestion> $questions
     * @param array<int, AssessmentResponse> $responsesByAttemptQuestionId
     * @return array{
     *   marks_awarded: string,
     *   marks_available: string,
     *   score_percent: string,
     *   passed: bool,
     *   per_question: array<int, array{is_correct: bool, marks_awarded: string}>
     * }
     */
    public function score(
        array $questions,
        array $responsesByAttemptQuestionId,
        string $passThresholdPercent,
    ): array {
        $awarded = 0.0;
        $available = 0.0;
        $perQuestion = [];

        foreach ($questions as $question) {
            $available += (float) $question->marks;
            $response = $responsesByAttemptQuestionId[$question->attemptQuestionId] ?? null;
            $selected = $response?->selectedOptionId;
            $isCorrect = $selected !== null && in_array($selected, $question->correctOptionIds, true);
            $qAwarded = $isCorrect ? (float) $question->marks : 0.0;
            $awarded += $qAwarded;
            $perQuestion[$question->attemptQuestionId] = [
                'is_correct' => $isCorrect,
                'marks_awarded' => number_format($qAwarded, 2, '.', ''),
            ];
        }

        $scorePercent = $available <= 0.0 ? 0.0 : ($awarded / $available) * 100.0;
        $scoreFormatted = number_format($scorePercent, 2, '.', '');
        $passed = (float) $scoreFormatted >= (float) $passThresholdPercent;

        return [
            'marks_awarded' => number_format($awarded, 2, '.', ''),
            'marks_available' => number_format($available, 2, '.', ''),
            'score_percent' => $scoreFormatted,
            'passed' => $passed,
            'per_question' => $perQuestion,
        ];
    }
}
