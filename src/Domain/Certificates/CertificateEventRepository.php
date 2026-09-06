<?php

declare(strict_types=1);

namespace Academy\Domain\Certificates;

use DateTimeImmutable;

interface CertificateEventRepository
{
    public function append(
        int $certificateId,
        string $eventType,
        ?int $actorUserId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void;
}
