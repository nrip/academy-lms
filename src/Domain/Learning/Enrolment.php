<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class Enrolment
{
    public function __construct(
        public readonly int $enrolmentId,
        public readonly string $publicReference,
        public readonly int $applicationId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $courseVersionId,
        public readonly int $batchId,
        public readonly int $paymentId,
        public readonly string $lifecycleStatus,
        public readonly ?string $academicStatus,
        public readonly DateTimeImmutable $admittedAt,
        public readonly ?DateTimeImmutable $activatedAt,
        public readonly ?DateTimeImmutable $accessExpiresAt,
        public readonly int $rowVersion,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function belongsToUser(int $userId): bool
    {
        return $this->userId === $userId;
    }
}
