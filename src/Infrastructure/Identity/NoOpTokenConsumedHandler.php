<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\TokenConsumedHandler;

final class NoOpTokenConsumedHandler implements TokenConsumedHandler
{
    public function onConsumed(int $userId, string $purpose, int $verificationTokenId): void
    {
    }
}
