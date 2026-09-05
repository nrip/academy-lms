<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

final class AssessmentsAuditPayload implements AuditPayload
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
            'bank_id',
            'course_id',
            'title',
            'question_id',
            'question_type',
            'stem_length',
            'marks',
            'version',
            'status',
            'option_count',
            'correct_option_count',
        ];
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function assertAllowListed(array $payload): void
    {
        $allowed = array_fill_keys(self::allowedFields(), true);
        foreach (array_keys($payload) as $key) {
            if (!isset($allowed[$key])) {
                throw new \InvalidArgumentException('Assessments audit field not allow-listed: ' . $key);
            }
        }
    }
}
