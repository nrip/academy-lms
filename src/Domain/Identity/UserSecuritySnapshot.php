<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

/**
 * Minimal security snapshot for AuthenticationMiddleware.
 * Does not include password_hash, permissions, or MFA secrets.
 */
final class UserSecuritySnapshot
{
    public function __construct(
        public readonly int $userId,
        public readonly string $accountStatus,
        public readonly ?\DateTimeImmutable $lockedUntil,
        public readonly int $authVersion,
        public readonly bool $hasPrivilegedRole,
    ) {
    }

    public function isSuspended(): bool
    {
        return $this->accountStatus === AccountStatus::SUSPENDED;
    }

    public function isTemporarilyLocked(\DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > $now;
    }
}
