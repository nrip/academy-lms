<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

final class SecurityAuditPayload implements AuditPayload
{
    /**
     * @param array<string, scalar|null> $previous
     * @param array<string, scalar|null> $next
     */
    public function __construct(
        private readonly string $action,
        private readonly string $entityType,
        private readonly string $entityId,
        private readonly array $previous = [],
        private readonly array $next = [],
        private readonly ?string $reason = null,
    ) {
        $this->assertAllowListed($previous);
        $this->assertAllowListed($next);
    }

    public function action(): string
    {
        return $this->action;
    }

    public function affectedEntityType(): string
    {
        return $this->entityType;
    }

    public function affectedEntityId(): string
    {
        return $this->entityId;
    }

    public function previousValue(): ?array
    {
        return $this->previous === [] ? null : $this->previous;
    }

    public function newValue(): ?array
    {
        return $this->next === [] ? null : $this->next;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Explicit safe fields for foundation security events.
     *
     * @return list<string>
     */
    public static function allowedFields(): array
    {
        return [
            'session_id',
            'user_id',
            'policy_key',
            'status',
            'attempt_count',
            'event_type',
            'aggregate_type',
            'aggregate_id',
            'timeout_class',
            'revoked',
        ];
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    private function assertAllowListed(array $fields): void
    {
        $allowed = array_fill_keys(self::allowedFields(), true);
        foreach (array_keys($fields) as $key) {
            if (!isset($allowed[$key])) {
                throw new \InvalidArgumentException(sprintf('Audit field "%s" is not allow-listed.', $key));
            }
        }
    }
}
