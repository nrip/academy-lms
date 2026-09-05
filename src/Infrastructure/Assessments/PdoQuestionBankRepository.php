<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\QuestionBank;
use Academy\Domain\Assessments\QuestionBankRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoQuestionBankRepository implements QuestionBankRepository
{
    private const COLUMNS = 'bank_id, course_id, title, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $bankId): ?QuestionBank
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM question_banks WHERE bank_id = :id LIMIT 1');
        $stmt->execute(['id' => $bankId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByCourseId(int $courseId): ?QuestionBank
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM question_banks WHERE course_id = :course_id LIMIT 1');
        $stmt->execute(['course_id' => $courseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function insert(int $courseId, string $title): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO question_banks (course_id, title, created_at, updated_at)
             VALUES (:course_id, :title, :created_at, :updated_at)',
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => $title,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): QuestionBank
    {
        $utc = new DateTimeZone('UTC');

        return new QuestionBank(
            bankId: (int) $row['bank_id'],
            courseId: (int) $row['course_id'],
            title: (string) $row['title'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
