<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\AssessmentAttemptQuestion;
use Academy\Domain\Assessments\AssessmentAttemptQuestionRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoAssessmentAttemptQuestionRepository implements AssessmentAttemptQuestionRepository
{
    private const COLUMNS = 'attempt_question_id, attempt_id, question_id, question_version, sequence,
        stem, marks, options_json, correct_option_ids_json, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listByAttemptId(int $attemptId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM assessment_attempt_questions
             WHERE attempt_id = :attempt_id ORDER BY sequence ASC',
        );
        $stmt->execute(['attempt_id' => $attemptId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    public function insert(array $data): int
    {
        $pdo = $this->connections->connection();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO assessment_attempt_questions (
                attempt_id, question_id, question_version, sequence, stem, marks,
                options_json, correct_option_ids_json, created_at, updated_at
             ) VALUES (
                :attempt_id, :question_id, :question_version, :sequence, :stem, :marks,
                :options_json, :correct_option_ids_json, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'attempt_id' => $data['attempt_id'],
            'question_id' => $data['question_id'],
            'question_version' => $data['question_version'],
            'sequence' => $data['sequence'],
            'stem' => $data['stem'],
            'marks' => $data['marks'],
            'options_json' => json_encode($data['options'], JSON_THROW_ON_ERROR),
            'correct_option_ids_json' => json_encode($data['correct_option_ids'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): AssessmentAttemptQuestion
    {
        $utc = new DateTimeZone('UTC');
        /** @var list<array{option_id: int, sequence: int, option_text: string}> $options */
        $options = json_decode((string) $row['options_json'], true, 512, JSON_THROW_ON_ERROR);
        /** @var list<int> $correct */
        $correct = json_decode((string) $row['correct_option_ids_json'], true, 512, JSON_THROW_ON_ERROR);

        return new AssessmentAttemptQuestion(
            attemptQuestionId: (int) $row['attempt_question_id'],
            attemptId: (int) $row['attempt_id'],
            questionId: (int) $row['question_id'],
            questionVersion: (int) $row['question_version'],
            sequence: (int) $row['sequence'],
            stem: (string) $row['stem'],
            marks: (string) $row['marks'],
            options: $options,
            correctOptionIds: array_map(static fn (mixed $id): int => (int) $id, $correct),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
