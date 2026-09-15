<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\LearningQuestionResponse;
use Academy\Domain\Learning\LearningQuestionResponseRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoLearningQuestionResponseRepository implements LearningQuestionResponseRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insert(int $questionId, int $respondedByUserId, string $body, DateTimeImmutable $at): int
    {
        $pdo = $this->connections->connection();
        $atUtc = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO learning_question_responses (
                question_id, responded_by_user_id, body, responded_at, created_at
             ) VALUES (
                :question_id, :responded_by_user_id, :body, :responded_at, :created_at
             )',
        );
        $stmt->execute([
            'question_id' => $questionId,
            'responded_by_user_id' => $respondedByUserId,
            'body' => $body,
            'responded_at' => $atUtc,
            'created_at' => $atUtc,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function listForQuestion(int $questionId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT response_id, question_id, responded_by_user_id, body, responded_at, created_at
             FROM learning_question_responses
             WHERE question_id = :question_id
             ORDER BY responded_at ASC, response_id ASC',
        );
        $stmt->execute(['question_id' => $questionId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = new LearningQuestionResponse(
                responseId: (int) $row['response_id'],
                questionId: (int) $row['question_id'],
                respondedByUserId: (int) $row['responded_by_user_id'],
                body: (string) $row['body'],
                respondedAt: new DateTimeImmutable((string) $row['responded_at'], new DateTimeZone('UTC')),
                createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            );
        }

        return $rows;
    }

    public function countForQuestion(int $questionId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM learning_question_responses WHERE question_id = :question_id',
        );
        $stmt->execute(['question_id' => $questionId]);

        return (int) $stmt->fetchColumn();
    }
}
