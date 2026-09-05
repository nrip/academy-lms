<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\AssessmentResponse;
use Academy\Domain\Assessments\AssessmentResponseRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoAssessmentResponseRepository implements AssessmentResponseRepository
{
    private const COLUMNS = 'response_id, attempt_id, attempt_question_id, selected_option_id,
        is_correct, marks_awarded, answered_at, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listByAttemptId(int $attemptId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM assessment_responses WHERE attempt_id = :attempt_id',
        );
        $stmt->execute(['attempt_id' => $attemptId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    public function upsertSelection(
        int $attemptId,
        int $attemptQuestionId,
        ?int $selectedOptionId,
        DateTimeImmutable $at,
    ): AssessmentResponse {
        $pdo = $this->connections->connection();
        $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $existing = $pdo->prepare(
            'SELECT response_id FROM assessment_responses
             WHERE attempt_id = :attempt_id AND attempt_question_id = :attempt_question_id',
        );
        $existing->execute([
            'attempt_id' => $attemptId,
            'attempt_question_id' => $attemptQuestionId,
        ]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO assessment_responses (
                    attempt_id, attempt_question_id, selected_option_id, is_correct, marks_awarded,
                    answered_at, created_at, updated_at
                 ) VALUES (
                    :attempt_id, :attempt_question_id, :selected_option_id, NULL, NULL,
                    :answered_at, :created_at, :updated_at
                 )',
            );
            $stmt->execute([
                'attempt_id' => $attemptId,
                'attempt_question_id' => $attemptQuestionId,
                'selected_option_id' => $selectedOptionId,
                'answered_at' => $selectedOptionId === null ? null : $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE assessment_responses SET
                    selected_option_id = :selected_option_id,
                    answered_at = :answered_at,
                    updated_at = :updated_at
                 WHERE response_id = :response_id',
            );
            $stmt->execute([
                'selected_option_id' => $selectedOptionId,
                'answered_at' => $selectedOptionId === null ? null : $stamp,
                'updated_at' => $stamp,
                'response_id' => (int) $row['response_id'],
            ]);
        }

        $reloaded = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM assessment_responses
             WHERE attempt_id = :attempt_id AND attempt_question_id = :attempt_question_id',
        );
        $reloaded->execute([
            'attempt_id' => $attemptId,
            'attempt_question_id' => $attemptQuestionId,
        ]);
        $mapped = $reloaded->fetch(PDO::FETCH_ASSOC);
        if ($mapped === false) {
            throw new \RuntimeException('Assessment response missing after upsert.');
        }

        return $this->mapRow($mapped);
    }

    public function applyScores(int $attemptId, array $scoredByAttemptQuestionId): void
    {
        $pdo = $this->connections->connection();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $update = $pdo->prepare(
            'UPDATE assessment_responses SET
                is_correct = :is_correct,
                marks_awarded = :marks_awarded,
                updated_at = :updated_at
             WHERE attempt_id = :attempt_id AND attempt_question_id = :attempt_question_id',
        );
        $insert = $pdo->prepare(
            'INSERT INTO assessment_responses (
                attempt_id, attempt_question_id, selected_option_id, is_correct, marks_awarded,
                answered_at, created_at, updated_at
             ) VALUES (
                :attempt_id, :attempt_question_id, NULL, :is_correct, :marks_awarded,
                NULL, :created_at, :updated_at
             )',
        );
        foreach ($scoredByAttemptQuestionId as $attemptQuestionId => $score) {
            $update->execute([
                'is_correct' => $score['is_correct'] ? 1 : 0,
                'marks_awarded' => $score['marks_awarded'],
                'updated_at' => $now,
                'attempt_id' => $attemptId,
                'attempt_question_id' => $attemptQuestionId,
            ]);
            if ($update->rowCount() === 0) {
                $insert->execute([
                    'attempt_id' => $attemptId,
                    'attempt_question_id' => $attemptQuestionId,
                    'is_correct' => $score['is_correct'] ? 1 : 0,
                    'marks_awarded' => $score['marks_awarded'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): AssessmentResponse
    {
        $utc = new DateTimeZone('UTC');

        return new AssessmentResponse(
            responseId: (int) $row['response_id'],
            attemptId: (int) $row['attempt_id'],
            attemptQuestionId: (int) $row['attempt_question_id'],
            selectedOptionId: $row['selected_option_id'] === null ? null : (int) $row['selected_option_id'],
            isCorrect: $row['is_correct'] === null ? null : ((int) $row['is_correct'] === 1),
            marksAwarded: $row['marks_awarded'] === null ? null : (string) $row['marks_awarded'],
            answeredAt: $row['answered_at'] === null ? null : new DateTimeImmutable((string) $row['answered_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
