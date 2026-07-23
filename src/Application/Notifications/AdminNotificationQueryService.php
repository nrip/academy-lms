<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Notifications\NotificationDelivery;
use Academy\Domain\Notifications\NotificationDeliveryRepository;
use Academy\Domain\Security\AuthContext;

final class AdminNotificationQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly NotificationDeliveryRepository $deliveries,
    ) {
    }

    /**
     * @return array{items: list<NotificationDelivery>, total: int, status: ?string}
     */
    public function list(AuthContext $auth, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $this->authorization->require($auth, 'notification.view');
        $this->requireFullyAuthenticated($auth);

        return [
            'items' => $this->deliveries->listForOps($limit, $offset, $status),
            'total' => $this->deliveries->countForOps($status),
            'status' => $status,
        ];
    }

    public function detail(AuthContext $auth, int $deliveryId): NotificationDelivery
    {
        $this->authorization->require($auth, 'notification.view');
        $this->requireFullyAuthenticated($auth);
        $delivery = $this->deliveries->findById($deliveryId);
        if ($delivery === null) {
            throw new NotFoundException('Notification delivery not found.');
        }

        return $delivery;
    }

    private function requireFullyAuthenticated(AuthContext $auth): void
    {
        if (!$auth->authenticated || $auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }
        if ($auth->authStage !== AuthStage::FULLY_AUTHENTICATED) {
            throw new AuthenticationException('Full authentication required.');
        }
    }
}
