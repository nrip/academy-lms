<?php

declare(strict_types=1);

namespace Academy\Application\RBAC;

use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\MfaAssuranceAllowList;
use Academy\Domain\RBAC\PendingVerificationAllowList;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\Security\AuthContext;
use Throwable;

final class AuthorizationService
{
    public function __construct(
        private readonly PermissionRepository $permissions,
    ) {
    }

    public function check(AuthContext $context, string $permissionKey): bool
    {
        try {
            $this->require($context, $permissionKey);

            return true;
        } catch (AuthenticationException | AuthorizationException) {
            return false;
        }
    }

    /**
     * @throws AuthenticationException when unauthenticated
     * @throws AuthorizationException when evaluated deny / incomplete assurance
     * @throws ServiceUnavailableException when permission store fails
     */
    public function require(AuthContext $context, string $permissionKey): void
    {
        if (!$context->authenticated || $context->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        try {
            $keys = $this->permissions->permissionKeysForUser($context->userId);
        } catch (ServiceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ServiceUnavailableException('Permission store unavailable.');
        }

        if (!in_array($permissionKey, $keys, true)) {
            throw new AuthorizationException('Permission denied.');
        }

        if ($context->accountStatus === AccountStatus::PENDING_VERIFICATION) {
            if (!PendingVerificationAllowList::contains($permissionKey)) {
                throw new AuthorizationException('Permission denied.');
            }
        }

        if ($context->authStage === AuthStage::FULLY_AUTHENTICATED) {
            return;
        }

        // Incomplete privileged assurance: only explicit MFA allow-list may pass (B-3).
        if (MfaAssuranceAllowList::contains($permissionKey)) {
            return;
        }

        throw new AuthorizationException('Permission denied.');
    }
}
