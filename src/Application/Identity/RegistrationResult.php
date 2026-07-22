<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

final readonly class RegistrationResult
{
    public function __construct(
        public bool $created,
        public ?int $userId,
    ) {
    }
}
