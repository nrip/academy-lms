<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Domain\Identity\TokenConsumedHandler;

final class CompositeTokenConsumedHandler implements TokenConsumedHandler
{
    /**
     * @param list<TokenConsumedHandler> $handlers
     */
    public function __construct(
        private readonly array $handlers,
    ) {
    }

    public function onConsumed(int $userId, string $purpose, int $verificationTokenId): void
    {
        foreach ($this->handlers as $handler) {
            $handler->onConsumed($userId, $purpose, $verificationTokenId);
        }
    }
}
