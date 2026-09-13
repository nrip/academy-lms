<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

final class CoursesAuditPayload implements AuditPayload
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
            'course_id',
            'course_code',
            'slug',
            'master_title',
            'status',
            'version_id',
            'version_number',
            'title',
            'admission_mode',
            'standard_fee',
            'gst_rate',
            'currency',
            'admin_user_id',
            'scope_type',
            'include_future_versions',
            'scope_assignment_id',
            'module_id',
            'sequence',
            'description',
            'mandatory_flag',
            'release_rule',
            'prerequisite_module_id',
            'content_id',
            'content_type',
            'completion_rule',
            'body_text_length',
            'object_key_present',
            'video_provider',
            'video_delivery_mode',
            'media_mime',
            'live_provider',
            'cloned_from_version_id',
            'published_at',
            'locked_reason',
            'batch_id',
            'batch_code',
            'name',
            'delivery_mode',
            'min_capacity',
            'max_capacity',
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
