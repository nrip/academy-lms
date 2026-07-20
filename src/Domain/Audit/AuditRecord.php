<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

final class AuditRecord
{
    /**
     * @param array<string, mixed>|null $previousValue
     * @param array<string, mixed>|null $newValue
     */
    public function __construct(
        public readonly ?int $actorUserId,
        public readonly string $actorType,
        public readonly string $action,
        public readonly string $affectedEntityType,
        public readonly string $affectedEntityId,
        public readonly ?array $previousValue,
        public readonly ?array $newValue,
        public readonly ?string $reason,
        public readonly string $source,
        public readonly ?string $correlationId,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgentHash,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
