<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class EnrolmentTransitionResult
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly DateTimeImmutable $transitionedAt,
        public readonly ?string $reason,
    ) {
    }
}
