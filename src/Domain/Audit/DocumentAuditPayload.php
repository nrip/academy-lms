<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

final class DocumentAuditPayload implements AuditPayload
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
            'application_id',
            'requirement_id',
            'document_submission_id',
            'old_document_submission_id',
            'assignment_id',
            'reviewer_user_id',
            'status',
            'scan_status',
            'state_version',
            'row_version',
            'result',
            'reason_code',
            'authorization_id',
            'declaration_version',
            'scan_attempt_count',
            'object_key_suffix',
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
