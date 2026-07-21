<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\RBAC;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\Security\AuthContext;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    public function testUnauthenticatedThrowsAuthenticationException(): void
    {
        $service = new AuthorizationService($this->permissions(['rbac.role.view']));
        $this->expectException(AuthenticationException::class);
        $service->require(AuthContext::guest(1), 'rbac.role.view');
    }

    public function testMissingPermissionThrowsAuthorizationException(): void
    {
        $service = new AuthorizationService($this->permissions(['application.create']));
        $context = AuthContext::authenticated(1, 1, AuthStage::FULLY_AUTHENTICATED, 1, false, 'active');
        $this->expectException(AuthorizationException::class);
        $service->require($context, 'rbac.role.view');
    }

    public function testPrivilegedPermissionRequiresFullyAuthenticated(): void
    {
        $service = new AuthorizationService($this->permissions(['rbac.role.view', 'mfa.totp.enrol']));
        $context = AuthContext::authenticated(1, 1, AuthStage::MFA_ENROLMENT_REQUIRED, 1, true, 'active');
        $this->expectException(AuthorizationException::class);
        $service->require($context, 'rbac.role.view');
    }

    public function testMfaAllowListPermittedUnderIncompleteAssurance(): void
    {
        $service = new AuthorizationService($this->permissions(['mfa.totp.enrol']));
        $context = AuthContext::authenticated(1, 1, AuthStage::MFA_ENROLMENT_REQUIRED, 1, true, 'active');
        $service->require($context, 'mfa.totp.enrol');
        self::assertTrue($service->check($context, 'mfa.totp.enrol'));
    }

    public function testFullyAuthenticatedGrantsUnionPermission(): void
    {
        $service = new AuthorizationService($this->permissions(['rbac.role.view']));
        $context = AuthContext::authenticated(1, 1, AuthStage::FULLY_AUTHENTICATED, 1, true, 'active');
        $service->require($context, 'rbac.role.view');
        self::assertTrue(true);
    }

    public function testUnknownPermissionKeyIsEvaluatedDenyNotStoreFailure(): void
    {
        $service = new AuthorizationService($this->permissions(['rbac.role.view']));
        $context = AuthContext::authenticated(1, 1, AuthStage::FULLY_AUTHENTICATED, 1, true, 'active');
        $this->expectException(AuthorizationException::class);
        $service->require($context, 'does.not.exist.permission');
    }

    /**
     * @param list<string> $keys
     */
    private function permissions(array $keys): PermissionRepository
    {
        return new class ($keys) implements PermissionRepository {
            /** @param list<string> $keys */
            public function __construct(private readonly array $keys)
            {
            }

            public function permissionKeysForUser(int $userId): array
            {
                return $this->keys;
            }

            public function permissionKeysForRoleKey(string $roleKey): array
            {
                return $this->keys;
            }
        };
    }
}
