<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

interface TokenConsumedHandler
{
    public function onConsumed(int $userId, string $purpose, int $verificationTokenId): void;
}
