<?php

declare(strict_types=1);

namespace Academy\Domain\Security;

use Academy\Domain\Identity\AuthStage;

final class AuthContext
{
    public function __construct(
        public readonly ?int $userId,
        public readonly int $sessionId,
        public readonly bool $authenticated,
        public readonly string $authStage = AuthStage::ANONYMOUS,
        public readonly ?int $authVersion = null,
        public readonly bool $hasPrivilegedRole = false,
        public readonly ?string $accountStatus = null,
    ) {
    }

    public static function guest(int $sessionId): self
    {
        return new self(
            userId: null,
            sessionId: $sessionId,
            authenticated: false,
            authStage: AuthStage::ANONYMOUS,
            authVersion: null,
            hasPrivilegedRole: false,
            accountStatus: null,
        );
    }

    public static function authenticated(
        int $userId,
        int $sessionId,
        string $authStage,
        int $authVersion,
        bool $hasPrivilegedRole,
        string $accountStatus,
    ): self {
        return new self(
            userId: $userId,
            sessionId: $sessionId,
            authenticated: true,
            authStage: $authStage,
            authVersion: $authVersion,
            hasPrivilegedRole: $hasPrivilegedRole,
            accountStatus: $accountStatus,
        );
    }

    public function isFullyAuthenticated(): bool
    {
        return $this->authenticated && $this->authStage === AuthStage::FULLY_AUTHENTICATED;
    }
}
