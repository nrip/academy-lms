<?php

declare(strict_types=1);

namespace Academy\Domain\Audit;

interface AuditWriter
{
    public function append(AuditRecord $record): void;
}
