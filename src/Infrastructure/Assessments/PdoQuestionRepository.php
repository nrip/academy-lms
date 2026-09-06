<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\Question;
use Academy\Domain\Assessments\QuestionRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoQuestionRepository implements QuestionRepository
{
    private const COLUMNS = 'question_id, bank_id, question_type, stem, marks, explanation, version, status,
        created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $questionId): ?Question
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM questions WHERE question_id = :id LIMIT 1');
        $stmt->execute(['id' => $questionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByBankId(int $bankId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM questions
             WHERE bank_id = :bank_id
             ORDER BY question_id ASC',
        );
        $stmt->execute(['bank_id' => $bankId]);

        $questions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $questions[] = $this->mapRow($row);
        }

        return $questions;
    }

    public function insert(array $data): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO questions (
                bank_id, question_type, stem, marks, explanation, version, status, created_at, updated_at
             ) VALUES (
                :bank_id, :question_type, :stem, :marks, :explanation, :version, :status, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'bank_id' => $data['bank_id'],
            'question_type' => $data['question_type'],
            'stem' => $data['stem'],
            'marks' => $data['marks'],
            'explanation' => $data['explanation'],
            'version' => $data['version'],
            'status' => $data['status'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $questionId, array $data): bool
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE questions SET
                stem = :stem,
                marks = :marks,
                explanation = :explanation,
                status = :status,
                version = :version,
                updated_at = :updated_at
             WHERE question_id = :question_id',
        );
        $stmt->execute([
            'stem' => $data['stem'],
            'marks' => $data['marks'],
            'explanation' => $data['explanation'],
            'status' => $data['status'],
            'version' => $data['version'],
            'updated_at' => $now,
            'question_id' => $questionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $questionId): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('DELETE FROM questions WHERE question_id = :question_id');
        $stmt->execute(['question_id' => $questionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Question
    {
        $utc = new DateTimeZone('UTC');

        return new Question(
            questionId: (int) $row['question_id'],
            bankId: (int) $row['bank_id'],
            questionType: (string) $row['question_type'],
            stem: (string) $row['stem'],
            marks: number_format((float) $row['marks'], 2, '.', ''),
            explanation: $row['explanation'] === null ? null : (string) $row['explanation'],
            version: (int) $row['version'],
            status: (string) $row['status'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
