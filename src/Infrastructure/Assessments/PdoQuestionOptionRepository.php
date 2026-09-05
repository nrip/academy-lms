<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\QuestionOption;
use Academy\Domain\Assessments\QuestionOptionRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoQuestionOptionRepository implements QuestionOptionRepository
{
    private const COLUMNS = 'option_id, question_id, sequence, option_text, is_correct, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listByQuestionId(int $questionId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM question_options
             WHERE question_id = :question_id
             ORDER BY sequence ASC, option_id ASC',
        );
        $stmt->execute(['question_id' => $questionId]);

        $options = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $options[] = $this->mapRow($row);
        }

        return $options;
    }

    public function insert(array $data): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO question_options (
                question_id, sequence, option_text, is_correct, created_at, updated_at
             ) VALUES (
                :question_id, :sequence, :option_text, :is_correct, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'question_id' => $data['question_id'],
            'sequence' => $data['sequence'],
            'option_text' => $data['option_text'],
            'is_correct' => $data['is_correct'] ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function deleteByQuestionId(int $questionId): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('DELETE FROM question_options WHERE question_id = :question_id');
        $stmt->execute(['question_id' => $questionId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): QuestionOption
    {
        $utc = new DateTimeZone('UTC');

        return new QuestionOption(
            optionId: (int) $row['option_id'],
            questionId: (int) $row['question_id'],
            sequence: (int) $row['sequence'],
            optionText: (string) $row['option_text'],
            isCorrect: (int) $row['is_correct'] === 1,
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
