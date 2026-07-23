<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class EnrolmentStatusHistoryWrite
{
    public function __construct(
        public readonly int $enrolmentId,
        public readonly int $applicationId,
        public readonly string $lifecycleBefore,
        public readonly string $lifecycleAfter,
        public readonly string $source,
        public readonly ?string $reason,
        public readonly ?int $actorUserId,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
