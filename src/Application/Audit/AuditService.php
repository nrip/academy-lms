<?php

declare(strict_types=1);

namespace Academy\Application\Audit;

use Academy\Domain\Audit\AuditPayload;
use Academy\Domain\Audit\AuditRecord;
use Academy\Domain\Audit\AuditWriter;

final class AuditService
{
    public function __construct(
        private readonly AuditWriter $writer,
        private readonly AuditRedactor $redactor,
    ) {
    }

    public function record(
        AuditPayload $payload,
        string $actorType,
        ?int $actorUserId,
        string $source,
        ?string $correlationId = null,
        ?string $ipAddress = null,
        ?string $userAgentHash = null,
        ?\DateTimeImmutable $occurredAt = null,
    ): void {
        $this->writer->append(new AuditRecord(
            actorUserId: $actorUserId,
            actorType: $actorType,
            action: $payload->action(),
            affectedEntityType: $payload->affectedEntityType(),
            affectedEntityId: $payload->affectedEntityId(),
            previousValue: $this->redactor->redact($payload->previousValue()),
            newValue: $this->redactor->redact($payload->newValue()),
            reason: $payload->reason(),
            source: $source,
            correlationId: $correlationId,
            ipAddress: $ipAddress,
            userAgentHash: $userAgentHash,
            occurredAt: $occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }
}
