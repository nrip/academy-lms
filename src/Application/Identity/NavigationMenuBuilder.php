<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Security\AuthContext;

/**
 * Permission-based navigation for the authenticated shell.
 * Shows only links the caller is authorized to use.
 */
final class NavigationMenuBuilder
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    /**
     * @return list<array{label: string, href: string, method?: string}>
     */
    public function build(?AuthContext $auth): array
    {
        if ($auth === null || !$auth->authenticated || $auth->userId === null) {
            return [
                ['label' => 'Courses', 'href' => '/courses'],
                ['label' => 'Sign in', 'href' => '/login'],
            ];
        }

        $items = [];

        if ($auth->accountStatus === AccountStatus::ACTIVE) {
            // Public catalogue is readable without a dedicated permission; show to learners.
            if ($this->authorization->check($auth, 'dashboard.view_own')) {
                $items[] = ['label' => 'Courses', 'href' => PostLoginDestinationResolver::COURSES];
                $items[] = ['label' => 'My Applications', 'href' => PostLoginDestinationResolver::LEARNER_DASHBOARD];
            }
            if ($this->authorization->check($auth, 'profile.personal.view_own')
                && $this->authorization->check($auth, 'dashboard.view_own')
            ) {
                $items[] = ['label' => 'Profile', 'href' => PostLoginDestinationResolver::PROFILE];
            }
            if ($this->authorization->check($auth, 'reviewer.queue.view')) {
                $items[] = ['label' => 'Reviewer Queue', 'href' => PostLoginDestinationResolver::REVIEWER_QUEUE];
            }
            if ($this->authorization->check($auth, 'finance.payment.view')) {
                $items[] = ['label' => 'Payments', 'href' => PostLoginDestinationResolver::FINANCE_PAYMENTS];
            }
            if ($this->authorization->check($auth, 'finance.payment.reconcile')) {
                $items[] = ['label' => 'Reconciliation', 'href' => PostLoginDestinationResolver::FINANCE_RECONCILIATION];
            }
            if ($this->authorization->check($auth, 'notification.view')) {
                $items[] = ['label' => 'Notifications', 'href' => PostLoginDestinationResolver::NOTIFICATION_OPS];
            }
        }

        $items[] = ['label' => 'Logout', 'href' => '/logout', 'method' => 'post'];

        return $items;
    }
}
