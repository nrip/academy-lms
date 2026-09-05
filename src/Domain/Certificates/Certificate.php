<?php

declare(strict_types=1);

namespace Academy\Domain\Certificates;

use DateTimeImmutable;

final class Certificate
{
    public function __construct(
        public readonly int $certificateId,
        public readonly int $enrolmentId,
        public readonly int $courseVersionId,
        public readonly string $certificateType,
        public readonly string $certificateNumber,
        public readonly string $verificationHash,
        public readonly string $learnerNameSnapshot,
        public readonly string $courseTitleSnapshot,
        public readonly string $versionTitleSnapshot,
        public readonly string $certificateLabel,
        public readonly string $status,
        public readonly ?int $currentMarker,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === CertificateStatus::ACTIVE;
    }

    public function isCurrent(): bool
    {
        return $this->currentMarker === 1;
    }
}
