<?php

declare(strict_types=1);

namespace Academy\Domain\Security;

final class AuthContext
{
    public function __construct(
        public readonly ?int $userId,
        public readonly int $sessionId,
        public readonly bool $authenticated,
    ) {
    }

    public static function guest(int $sessionId): self
    {
        return new self(null, $sessionId, false);
    }

    public static function authenticated(int $userId, int $sessionId): self
    {
        return new self($userId, $sessionId, true);
    }
}
