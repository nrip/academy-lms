<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use DateTimeImmutable;

final class LearnerQualification
{
    public function __construct(
        public readonly int $learnerQualificationId,
        public readonly int $learnerProfileId,
        public readonly string $qualificationType,
        public readonly string $qualificationName,
        public readonly string $institutionName,
        public readonly ?string $universityOrBoard,
        public readonly ?string $country,
        public readonly int $completionYear,
        public readonly ?string $registrationOrCertificateNumber,
        public readonly int $displayOrder,
        public readonly int $rowVersion,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
