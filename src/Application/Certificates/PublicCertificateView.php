<?php

declare(strict_types=1);

namespace Academy\Application\Certificates;

use DateTimeImmutable;

final class PublicCertificateView
{
    public function __construct(
        public readonly string $certificateNumber,
        public readonly string $learnerName,
        public readonly string $courseTitle,
        public readonly string $certificateType,
        public readonly string $certificateLabel,
        public readonly string $status,
        public readonly DateTimeImmutable $issuedAt,
        public readonly bool $isValid,
    ) {
    }
}
