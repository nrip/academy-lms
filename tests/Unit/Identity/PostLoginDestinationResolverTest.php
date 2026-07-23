<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Identity;

use Academy\Application\Identity\PostLoginDestinationResolver;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\Security\AuthContext;
use PHPUnit\Framework\TestCase;

final class PostLoginDestinationResolverTest extends TestCase
{
    public function testLearnerGoesToDashboard(): void
    {
        $resolver = $this->resolver(['dashboard.view_own', 'profile.personal.view_own']);
        $dest = $resolver->resolve($this->activeAuth());
        self::assertSame(PostLoginDestinationResolver::LEARNER_DASHBOARD, $dest);
    }

    public function testReviewerGoesToQueue(): void
    {
        $resolver = $this->resolver(['reviewer.queue.view', 'dashboard.view_own']);
        self::assertSame(
            PostLoginDestinationResolver::REVIEWER_QUEUE,
            $resolver->resolve($this->activeAuth(hasPrivilegedRole: true)),
        );
    }

    public function testFinanceGoesToReconciliation(): void
    {
        $resolver = $this->resolver(['finance.payment.reconcile', 'finance.payment.view']);
        self::assertSame(
            PostLoginDestinationResolver::FINANCE_RECONCILIATION,
            $resolver->resolve($this->activeAuth(hasPrivilegedRole: true)),
        );
    }

    public function testFinanceViewOnlyGoesToPayments(): void
    {
        $resolver = $this->resolver(['finance.payment.view']);
        self::assertSame(
            PostLoginDestinationResolver::FINANCE_PAYMENTS,
            $resolver->resolve($this->activeAuth(hasPrivilegedRole: true)),
        );
    }

    public function testNotificationOperatorGoesToAdminNotifications(): void
    {
        $resolver = $this->resolver(['notification.view']);
        self::assertSame(
            PostLoginDestinationResolver::NOTIFICATION_OPS,
            $resolver->resolve($this->activeAuth(hasPrivilegedRole: true)),
        );
    }

    public function testMultiPermissionFollowsDeterministicPrecedence(): void
    {
        $resolver = $this->resolver([
            'notification.view',
            'dashboard.view_own',
            'finance.payment.reconcile',
            'reviewer.queue.view',
        ]);
        self::assertSame(
            PostLoginDestinationResolver::REVIEWER_QUEUE,
            $resolver->resolve($this->activeAuth(hasPrivilegedRole: true)),
        );
    }

    public function testPendingDoesNotReceivePrivilegedDestination(): void
    {
        $resolver = $this->resolver([
            'reviewer.queue.view',
            'finance.payment.reconcile',
            'notification.view',
            'dashboard.view_own',
        ]);
        $auth = AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: 1,
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::PENDING_VERIFICATION,
        );
        self::assertSame(PostLoginDestinationResolver::COURSES, $resolver->resolve($auth));
        self::assertNull($resolver->honorReturnTo($auth, '/reviewer/applications'));
    }

    public function testSuspendedDoesNotReceivePrivilegedDestination(): void
    {
        $resolver = $this->resolver(['reviewer.queue.view', 'dashboard.view_own']);
        $auth = AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: 1,
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::SUSPENDED,
        );
        self::assertSame(PostLoginDestinationResolver::COURSES, $resolver->resolve($auth));
    }

    public function testValidatedInternalReturnToIsHonored(): void
    {
        $resolver = $this->resolver(['dashboard.view_own', 'profile.personal.view_own']);
        self::assertSame(
            '/profile',
            $resolver->resolve($this->activeAuth(), '/profile'),
        );
    }

    public function testExternalAndMalformedReturnToRejected(): void
    {
        $resolver = $this->resolver(['dashboard.view_own']);
        $auth = $this->activeAuth();
        self::assertNull($resolver->honorReturnTo($auth, 'https://evil.example/phish'));
        self::assertNull($resolver->honorReturnTo($auth, '//evil.example'));
        self::assertNull($resolver->honorReturnTo($auth, '/dashboard/../admin'));
        self::assertNull($resolver->honorReturnTo($auth, '/not-allow-listed'));
        self::assertNull($resolver->honorReturnTo($auth, 'dashboard'));
        self::assertSame(
            PostLoginDestinationResolver::LEARNER_DASHBOARD,
            $resolver->resolve($auth, 'https://evil.example/phish'),
        );
    }

    public function testReturnToPrivilegedDeniedWithoutPermission(): void
    {
        $resolver = $this->resolver(['dashboard.view_own']);
        self::assertNull($resolver->honorReturnTo($this->activeAuth(), '/reviewer/applications'));
    }

    public function testIncompleteMfaDoesNotReceivePrivilegedLanding(): void
    {
        $resolver = $this->resolver(['reviewer.queue.view', 'dashboard.view_own']);
        $auth = AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::MFA_CHALLENGE_REQUIRED,
            authVersion: 1,
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::ACTIVE,
        );
        // Privileged checks fail without full assurance → fall through to courses
        // (dashboard also denied for incomplete MFA on privileged account).
        self::assertSame(PostLoginDestinationResolver::COURSES, $resolver->resolve($auth));
    }

    /**
     * @param list<string> $keys
     */
    private function resolver(array $keys): PostLoginDestinationResolver
    {
        $repo = new class ($keys) implements PermissionRepository {
            /** @param list<string> $keys */
            public function __construct(private readonly array $keys)
            {
            }

            public function permissionKeysForUser(int $userId): array
            {
                unset($userId);

                return $this->keys;
            }

            public function permissionKeysForRoleKey(string $roleKey): array
            {
                unset($roleKey);

                return $this->keys;
            }
        };

        return new PostLoginDestinationResolver(new AuthorizationService($repo));
    }

    private function activeAuth(bool $hasPrivilegedRole = false): AuthContext
    {
        return AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: 1,
            hasPrivilegedRole: $hasPrivilegedRole,
            accountStatus: AccountStatus::ACTIVE,
        );
    }
}
