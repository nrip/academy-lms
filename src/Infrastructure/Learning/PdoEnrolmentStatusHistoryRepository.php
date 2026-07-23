<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\EnrolmentStatusHistoryRepository;
use Academy\Domain\Learning\EnrolmentStatusHistoryWrite;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeZone;

final class PdoEnrolmentStatusHistoryRepository implements EnrolmentStatusHistoryRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function append(EnrolmentStatusHistoryWrite $write): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO enrolment_status_history (
                enrolment_id, application_id, lifecycle_before, lifecycle_after,
                source, reason, actor_user_id, created_at
            ) VALUES (
                :enrolment_id, :application_id, :lifecycle_before, :lifecycle_after,
                :source, :reason, :actor_user_id, :created_at
            )',
        );
        $stmt->execute([
            'enrolment_id' => $write->enrolmentId,
            'application_id' => $write->applicationId,
            'lifecycle_before' => $write->lifecycleBefore,
            'lifecycle_after' => $write->lifecycleAfter,
            'source' => $write->source,
            'reason' => $write->reason,
            'actor_user_id' => $write->actorUserId,
            'created_at' => $write->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
