<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Identity;

use Academy\Application\Identity\NavigationMenuBuilder;
use Academy\Application\Identity\PostLoginDestinationResolver;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\Security\AuthContext;
use PHPUnit\Framework\TestCase;

final class NavigationMenuBuilderTest extends TestCase
{
    public function testLearnerNav(): void
    {
        $items = $this->builder(['dashboard.view_own', 'profile.personal.view_own'])->build($this->auth());
        $labels = array_column($items, 'label');

        self::assertSame(['Courses', 'My Applications', 'Profile', 'Logout'], $labels);
        self::assertSame(PostLoginDestinationResolver::LEARNER_DASHBOARD, $items[1]['href']);
    }

    public function testFinanceNav(): void
    {
        $labels = array_column(
            $this->builder(['finance.payment.view', 'finance.payment.reconcile'])->build($this->auth()),
            'label',
        );
        self::assertSame(['Payments', 'Reconciliation', 'Logout'], $labels);
    }

    public function testReviewerNav(): void
    {
        $labels = array_column(
            $this->builder(['reviewer.queue.view'])->build($this->auth()),
            'label',
        );
        self::assertSame(['Reviewer Queue', 'Logout'], $labels);
    }

    public function testCourseAdminNav(): void
    {
        $labels = array_column(
            $this->builder(['course.view_assigned', 'course.create'])->build($this->auth()),
            'label',
        );
        self::assertSame(['Course Admin', 'Logout'], $labels);
    }

    /**
     * @param list<string> $keys
     */
    private function builder(array $keys): NavigationMenuBuilder
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

        return new NavigationMenuBuilder(new AuthorizationService($repo));
    }

    private function auth(): AuthContext
    {
        return AuthContext::authenticated(
            userId: 1,
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: 1,
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::ACTIVE,
        );
    }
}
