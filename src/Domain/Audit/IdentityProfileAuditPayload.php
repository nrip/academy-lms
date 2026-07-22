<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

final class IdentityProfileAuditPayload implements AuditPayload
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
     * @return list<string>
     */
    public static function allowedFields(): array
    {
        return [
            'user_id',
            'learner_profile_id',
            'learner_qualification_id',
            'changed_field_keys',
            'row_version_before',
            'row_version_after',
            'qualification_type',
            'result',
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
