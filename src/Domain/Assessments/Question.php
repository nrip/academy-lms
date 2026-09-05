<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class Question
{
    public function __construct(
        public readonly int $questionId,
        public readonly int $bankId,
        public readonly string $questionType,
        public readonly string $stem,
        public readonly string $marks,
        public readonly ?string $explanation,
        public readonly int $version,
        public readonly string $status,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
