<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Certificates;

use Academy\Domain\Certificates\CertificateEventRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;

final class PdoCertificateEventRepository implements CertificateEventRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function append(
        int $certificateId,
        string $eventType,
        ?int $actorUserId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void {
        $pdo = $this->connections->connection();
        $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO certificate_events (
                certificate_id, event_type, actor_user_id, reason, created_at
             ) VALUES (
                :certificate_id, :event_type, :actor_user_id, :reason, :created_at
             )',
        );
        $stmt->execute([
            'certificate_id' => $certificateId,
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => $stamp,
        ]);
    }
}
