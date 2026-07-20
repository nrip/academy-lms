<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Audit;

use Academy\Domain\Audit\AuditRecord;
use Academy\Domain\Audit\AuditWriter;
use Academy\Infrastructure\Database\ConnectionFactory;

final class PdoAuditWriter implements AuditWriter
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function append(AuditRecord $record): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (
                actor_user_id, actor_type, action, affected_entity_type, affected_entity_id,
                previous_value, new_value, reason, source, correlation_id, ip_address, user_agent_hash,
                occurred_at, created_at
            ) VALUES (
                :actor_user_id, :actor_type, :action, :affected_entity_type, :affected_entity_id,
                :previous_value, :new_value, :reason, :source, :correlation_id, :ip_address, :user_agent_hash,
                :occurred_at, :created_at
            )',
        );

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt->execute([
            'actor_user_id' => $record->actorUserId,
            'actor_type' => $record->actorType,
            'action' => $record->action,
            'affected_entity_type' => $record->affectedEntityType,
            'affected_entity_id' => $record->affectedEntityId,
            'previous_value' => $record->previousValue === null ? null : json_encode($record->previousValue, JSON_THROW_ON_ERROR),
            'new_value' => $record->newValue === null ? null : json_encode($record->newValue, JSON_THROW_ON_ERROR),
            'reason' => $record->reason,
            'source' => $record->source,
            'correlation_id' => $record->correlationId,
            'ip_address' => $record->ipAddress !== null ? inet_pton($record->ipAddress) : null,
            'user_agent_hash' => $record->userAgentHash,
            'occurred_at' => $record->occurredAt->format('Y-m-d H:i:s.u'),
            'created_at' => $now,
        ]);
    }
}
