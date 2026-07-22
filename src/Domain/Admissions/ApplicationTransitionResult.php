<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use DateTimeImmutable;

final class ApplicationTransitionResult
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly DateTimeImmutable $transitionedAt,
        public readonly ?string $reason = null,
    ) {
    }
}
