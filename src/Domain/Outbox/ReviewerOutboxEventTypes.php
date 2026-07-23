<?php

declare(strict_types=1);

namespace Academy\Domain\Outbox;

final class ReviewerOutboxEventTypes
{
    public const APPLICATION_CORRECTION_REQUESTED = 'application.correction_requested';
    public const APPLICATION_APPROVED = 'application.approved';
    public const APPLICATION_REJECTED = 'application.rejected';
    public const APPLICATION_CORRECTIONS_RESUBMITTED = 'application.corrections_resubmitted';
}
