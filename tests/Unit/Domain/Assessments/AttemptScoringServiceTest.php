<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Assessments;

use Academy\Domain\Assessments\AssessmentAttemptQuestion;
use Academy\Domain\Assessments\AssessmentResponse;
use Academy\Domain\Assessments\AttemptScoringService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AttemptScoringServiceTest extends TestCase
{
    public function testScoresFromSnapshotNotMissingResponses(): void
    {
        $scoring = new AttemptScoringService();
        $questions = [
            $this->question(1, '1.00', [10]),
            $this->question(2, '1.00', [20]),
        ];
        $responses = [
            1 => $this->response(1, 10),
        ];

        $result = $scoring->score($questions, $responses, '50.00');

        self::assertSame('1.00', $result['marks_awarded']);
        self::assertSame('2.00', $result['marks_available']);
        self::assertSame('50.00', $result['score_percent']);
        self::assertTrue($result['passed']);
        self::assertTrue($result['per_question'][1]['is_correct']);
        self::assertFalse($result['per_question'][2]['is_correct']);
    }

    public function testWrongAnswerFailsThreshold(): void
    {
        $scoring = new AttemptScoringService();
        $questions = [$this->question(1, '1.00', [10])];
        $responses = [1 => $this->response(1, 99)];

        $result = $scoring->score($questions, $responses, '100.00');

        self::assertSame('0.00', $result['score_percent']);
        self::assertFalse($result['passed']);
    }

    private function question(int $attemptQuestionId, string $marks, array $correctIds): AssessmentAttemptQuestion
    {
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new AssessmentAttemptQuestion(
            attemptQuestionId: $attemptQuestionId,
            attemptId: 1,
            questionId: $attemptQuestionId * 10,
            questionVersion: 1,
            sequence: $attemptQuestionId,
            stem: 'Q' . $attemptQuestionId,
            marks: $marks,
            options: [
                ['option_id' => $correctIds[0], 'sequence' => 1, 'option_text' => 'A'],
                ['option_id' => 99, 'sequence' => 2, 'option_text' => 'B'],
            ],
            correctOptionIds: $correctIds,
            createdAt: $at,
            updatedAt: $at,
        );
    }

    private function response(int $attemptQuestionId, int $selectedOptionId): AssessmentResponse
    {
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new AssessmentResponse(
            responseId: $attemptQuestionId,
            attemptId: 1,
            attemptQuestionId: $attemptQuestionId,
            selectedOptionId: $selectedOptionId,
            isCorrect: null,
            marksAwarded: null,
            answeredAt: $at,
            createdAt: $at,
            updatedAt: $at,
        );
    }
}
