<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\TokenConsumedHandler;

final class RecordingTokenConsumedHandler implements TokenConsumedHandler
{
    /** @var list<array{user_id: int, purpose: string, verification_token_id: int}> */
    private array $events = [];

    public function onConsumed(int $userId, string $purpose, int $verificationTokenId): void
    {
        $this->events[] = [
            'user_id' => $userId,
            'purpose' => $purpose,
            'verification_token_id' => $verificationTokenId,
        ];
    }

    /**
     * @return list<array{user_id: int, purpose: string, verification_token_id: int}>
     */
    public function events(): array
    {
        return $this->events;
    }
}
