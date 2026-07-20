<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

/**
 * Typed audit payload with an explicit safe-field allow-list.
 * Implementations must never serialize arbitrary entities.
 */
interface AuditPayload
{
    public function action(): string;

    public function affectedEntityType(): string;

    public function affectedEntityId(): string;

    /**
     * @return array<string, mixed>|null
     */
    public function previousValue(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function newValue(): ?array;

    public function reason(): ?string;
}
