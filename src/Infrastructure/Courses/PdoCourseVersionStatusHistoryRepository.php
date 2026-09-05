<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\CourseVersionStatusHistoryRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;

final class PdoCourseVersionStatusHistoryRepository implements CourseVersionStatusHistoryRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function append(
        int $versionId,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorUserId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO course_version_status_history (
                version_id, from_status, to_status, actor_user_id, reason, created_at
             ) VALUES (
                :version_id, :from_status, :to_status, :actor_user_id, :reason, :created_at
             )',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
