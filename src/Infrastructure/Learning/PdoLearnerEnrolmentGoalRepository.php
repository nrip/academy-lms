<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\LearnerEnrolmentGoal;
use Academy\Domain\Learning\LearnerEnrolmentGoalRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoLearnerEnrolmentGoalRepository implements LearnerEnrolmentGoalRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findForEnrolment(int $enrolmentId): ?LearnerEnrolmentGoal
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT goal_id, enrolment_id, user_id, label, target_date, created_at, updated_at
             FROM learner_enrolment_goals
             WHERE enrolment_id = :enrolment_id
             LIMIT 1',
        );
        $stmt->execute(['enrolment_id' => $enrolmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($row) : null;
    }

    public function upsert(int $enrolmentId, int $userId, string $label, string $targetDate, DateTimeImmutable $at): int
    {
        $pdo = $this->connections->connection();
        $ts = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $existing = $this->findForEnrolment($enrolmentId);
        if ($existing !== null) {
            $stmt = $pdo->prepare(
                'UPDATE learner_enrolment_goals
                 SET label = :label, target_date = :target_date, updated_at = :updated_at
                 WHERE goal_id = :goal_id AND user_id = :user_id',
            );
            $stmt->execute([
                'label' => $label,
                'target_date' => $targetDate,
                'updated_at' => $ts,
                'goal_id' => $existing->goalId,
                'user_id' => $userId,
            ]);

            return $existing->goalId;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO learner_enrolment_goals (
                enrolment_id, user_id, label, target_date, created_at, updated_at
             ) VALUES (
                :enrolment_id, :user_id, :label, :target_date, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'user_id' => $userId,
            'label' => $label,
            'target_date' => $targetDate,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function deleteForEnrolment(int $enrolmentId, int $userId): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'DELETE FROM learner_enrolment_goals WHERE enrolment_id = :enrolment_id AND user_id = :user_id',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): LearnerEnrolmentGoal
    {
        return new LearnerEnrolmentGoal(
            goalId: (int) $row['goal_id'],
            enrolmentId: (int) $row['enrolment_id'],
            userId: (int) $row['user_id'],
            label: (string) $row['label'],
            targetDate: (string) $row['target_date'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }
}
