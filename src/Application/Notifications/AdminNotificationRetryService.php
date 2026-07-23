<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\NotificationAuditPayload;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Notifications\NotificationDelivery;
use Academy\Domain\Notifications\NotificationDeliveryRepository;
use Academy\Domain\Notifications\NotificationDeliveryStatus;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class AdminNotificationRetryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly TransactionManager $transactions,
        private readonly AuditService $audit,
    ) {
    }

    public function retry(AuthContext $auth, int $deliveryId): NotificationDelivery
    {
        $this->authorization->require($auth, 'notification.retry');
        if (!$auth->authenticated || $auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }
        if ($auth->authStage !== AuthStage::FULLY_AUTHENTICATED) {
            throw new AuthenticationException('Full authentication required.');
        }

        return $this->transactions->run(function () use ($auth, $deliveryId): NotificationDelivery {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $delivery = $this->deliveries->findByIdForUpdate($deliveryId);
            if ($delivery === null) {
                throw new NotFoundException('Notification delivery not found.');
            }
            if ($delivery->status !== NotificationDeliveryStatus::FAILED
                && $delivery->status !== NotificationDeliveryStatus::DEAD
            ) {
                throw new ConflictException('Only failed or dead deliveries can be retried.');
            }
            if (!$this->deliveries->requestManualRetry($deliveryId, $now)) {
                throw new ConflictException('Delivery could not be retried (concurrent claim).');
            }
            $this->audit->record(
                new NotificationAuditPayload(
                    'notification.retry_requested',
                    'notification_delivery',
                    (string) $deliveryId,
                    previous: ['status' => $delivery->status],
                    next: [
                        'delivery_id' => $deliveryId,
                        'user_id' => $auth->userId,
                        'status' => NotificationDeliveryStatus::PENDING,
                        'attempt_count' => $delivery->attemptCount,
                    ],
                ),
                'user',
                $auth->userId,
                'admin',
            );
            $updated = $this->deliveries->findById($deliveryId);
            if ($updated === null) {
                throw new NotFoundException('Notification delivery not found.');
            }

            return $updated;
        });
    }
}
