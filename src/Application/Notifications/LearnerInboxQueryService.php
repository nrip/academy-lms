<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Notifications\InAppNotification;
use Academy\Domain\Notifications\InAppNotificationRepository;
use Academy\Domain\Security\AuthContext;
use DateTimeImmutable;
use DateTimeZone;

final class LearnerInboxQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly InAppNotificationRepository $notifications,
    ) {
    }

    /**
     * @return array{items: list<InAppNotification>, unread: int}
     */
    public function listOwn(AuthContext $auth): array
    {
        $userId = $this->requireUser($auth);

        return [
            'items' => $this->notifications->listForUser($userId, 50),
            'unread' => $this->notifications->countUnread($userId),
        ];
    }

    public function markRead(AuthContext $auth, int $notificationId): void
    {
        $userId = $this->requireUser($auth);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$this->notifications->markRead($notificationId, $userId, $now)) {
            throw new NotFoundException('Update not found.');
        }
    }

    private function requireUser(AuthContext $auth): int
    {
        $this->authorization->require($auth, 'dashboard.view_own');
        if (!$auth->authenticated || $auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
