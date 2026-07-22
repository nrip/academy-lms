<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use DateTimeImmutable;

final class DocumentSubmissionTransitionResult
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly string $scanStatus,
        public readonly DateTimeImmutable $transitionedAt,
        public readonly ?string $reasonCode = null,
    ) {
    }
}
