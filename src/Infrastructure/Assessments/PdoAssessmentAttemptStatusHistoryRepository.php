<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\AssessmentAttemptStatusHistoryRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;

final class PdoAssessmentAttemptStatusHistoryRepository implements AssessmentAttemptStatusHistoryRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function append(
        int $attemptId,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorUserId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO assessment_attempt_status_history (
                attempt_id, from_status, to_status, actor_user_id, reason, created_at
             ) VALUES (
                :attempt_id, :from_status, :to_status, :actor_user_id, :reason, :created_at
             )',
        );
        $stmt->execute([
            'attempt_id' => $attemptId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
