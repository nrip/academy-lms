<?php

declare(strict_types=1);

namespace Academy\Domain\Outbox;

final class DocumentOutboxEventTypes
{
    public const DOCUMENT_SCAN_REQUESTED = 'document.scan_requested';
    public const APPLICATION_SUBMITTED = 'application.submitted';
    public const DOCUMENT_SCAN_STUCK_ALERT = 'document.scan_stuck_alert';
}
