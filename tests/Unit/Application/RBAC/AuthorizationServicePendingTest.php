<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\RBAC;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\Security\AuthContext;
use PHPUnit\Framework\TestCase;

/**
 * WP-01B-2b: pending_verification accounts may only exercise the narrow
 * PendingVerificationAllowList even when their role grants a broader permission.
 */
final class AuthorizationServicePendingTest extends TestCase
{
    private const APPLICANT_KEYS = [
        'identity.session.view_own',
        'identity.session.revoke_own',
        'identity.password.change_own',
        'identity.verification.view_own',
        'identity.verification.resend_own',
        'profile.personal.view_own',
        'profile.personal.edit_own',
        'application.create',
        'application.view_own',
    ];

    public function testPendingAllowsIdentityVerificationViewOwn(): void
    {
        $service = new AuthorizationService($this->permissions(self::APPLICANT_KEYS));
        $context = $this->pendingContext();

        $service->require($context, 'identity.verification.view_own');
        self::assertTrue($service->check($context, 'identity.verification.view_own'));
    }

    public function testPendingAllowsIdentityVerificationResendOwn(): void
    {
        $service = new AuthorizationService($this->permissions(self::APPLICANT_KEYS));
        $context = $this->pendingContext();

        $service->require($context, 'identity.verification.resend_own');
        self::assertTrue(true);
    }

    public function testPendingDeniesApplicationCreateEvenThoughRoleGrantsIt(): void
    {
        $service = new AuthorizationService($this->permissions(self::APPLICANT_KEYS));
        $context = $this->pendingContext();

        $this->expectException(AuthorizationException::class);
        $service->require($context, 'application.create');
    }

    public function testPendingDeniesApplicationViewOwnEvenThoughRoleGrantsIt(): void
    {
        $service = new AuthorizationService($this->permissions(self::APPLICANT_KEYS));
        $context = $this->pendingContext();

        self::assertFalse($service->check($context, 'application.view_own'));
    }

    public function testPendingDeniesFinanceDashboardView(): void
    {
        $service = new AuthorizationService($this->permissions([...self::APPLICANT_KEYS, 'finance.dashboard.view']));
        $context = $this->pendingContext();

        $this->expectException(AuthorizationException::class);
        $service->require($context, 'finance.dashboard.view');
    }

    public function testPendingDeniesFinanceRefundApprove(): void
    {
        $service = new AuthorizationService($this->permissions([...self::APPLICANT_KEYS, 'finance.refund.approve']));
        $context = $this->pendingContext();

        self::assertFalse($service->check($context, 'finance.refund.approve'));
    }

    public function testPendingDeniesPermissionNotGrantedAtAll(): void
    {
        $service = new AuthorizationService($this->permissions(self::APPLICANT_KEYS));
        $context = $this->pendingContext();

        $this->expectException(AuthorizationException::class);
        $service->require($context, 'finance.payment.view');
    }

    public function testActiveStatusStillAllowsApplicationCreate(): void
    {
        $service = new AuthorizationService($this->permissions(self::APPLICANT_KEYS));
        $context = AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: 1,
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        $service->require($context, 'application.create');
        self::assertTrue($service->check($context, 'application.view_own'));
    }

    public function testActiveStatusIsUnaffectedByPendingAllowList(): void
    {
        $service = new AuthorizationService($this->permissions(self::APPLICANT_KEYS));
        $context = AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: 1,
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        self::assertTrue($service->check($context, 'identity.verification.view_own'));
    }

    private function pendingContext(): AuthContext
    {
        return AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: 1,
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::PENDING_VERIFICATION,
        );
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
