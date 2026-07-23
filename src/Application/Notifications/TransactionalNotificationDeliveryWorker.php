<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\NotificationAuditPayload;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\NotificationDeliveryRepository;
use Academy\Domain\Notifications\NotificationDeliveryStatus;
use Academy\Domain\Notifications\NotificationFailureCategory;
use Academy\Domain\Notifications\NotificationRetryPolicy;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use Academy\Domain\Outbox\OutboxMessage;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Infrastructure\Database\TransactionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims Mode A transactional outbox events, creates idempotent delivery rows,
 * sends email outside the DB transaction, then finalises with lease fencing.
 */
final class TransactionalNotificationDeliveryWorker
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly NotificationContextResolver $contextResolver,
        private readonly TransactionalNotificationTemplateRegistry $templates,
        private readonly NotificationTemplateRenderer $renderer,
        private readonly EmailDeliveryPort $emailPort,
        private readonly NotificationRetryPolicy $retryPolicy,
        private readonly TransactionManager $transactions,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
        private readonly int $leaseSeconds,
    ) {
    }

    /**
     * @return int Number of outbox messages successfully published or dead-lettered
     */
    public function run(string $workerId, int $limit = 10): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $this->outbox->claimByEventTypes(
            $workerId,
            $now,
            $this->leaseSeconds,
            TransactionalNotificationEventTypes::all(),
            $limit,
        );
        $processed = 0;

        foreach ($claimed as $message) {
            try {
                if ($this->processOne($message, $workerId)) {
                    ++$processed;
                }
            } catch (Throwable $exception) {
                $this->logger->warning('Transactional notification delivery failed.', [
                    'outbox_message_id' => $message->id,
                    'event_type' => $message->eventType,
                    'exception' => $exception::class,
                ]);
                $this->softFailOutbox($message, NotificationFailureCategory::PROVIDER_TRANSIENT);
            }
        }

        $processed += $this->processRetryableDeliveries($workerId, $limit);

        return $processed;
    }

    /**
     * Ops/manual retries and delivery-row backoff: claim delivery rows directly.
     */
    private function processRetryableDeliveries(string $workerId, int $limit): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ready = $this->deliveries->listReadyForRetry($limit, $now);
        $processed = 0;
        foreach ($ready as $delivery) {
            if ($delivery->status === NotificationDeliveryStatus::DELIVERED
                || $delivery->status === NotificationDeliveryStatus::DEAD
            ) {
                continue;
            }
            $message = $this->outbox->findMessageById($delivery->outboxMessageId);
            if ($message === null) {
                continue;
            }
            try {
                if ($this->processDeliveryRetry($message, $delivery->notificationDeliveryId, $workerId)) {
                    ++$processed;
                }
            } catch (Throwable $exception) {
                $this->logger->warning('Transactional notification retry failed.', [
                    'notification_delivery_id' => $delivery->notificationDeliveryId,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $processed;
    }

    private function processDeliveryRetry(OutboxMessage $message, int $deliveryId, string $workerId): bool
    {
        $template = $this->templates->forEventType($message->eventType);
        try {
            $context = $this->contextResolver->resolve($message);
        } catch (DomainRuleException $exception) {
            $category = $this->mapPrepFailure($exception->getMessage());
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->deliveries->markDeadFromPrep($deliveryId, $category, $now);

            return true;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $leaseToken = $this->newLeaseToken();
        $claimedDelivery = $this->deliveries->claimForSend(
            $deliveryId,
            $workerId,
            $leaseToken,
            $now,
            $this->leaseSeconds,
        );
        if ($claimedDelivery === null) {
            return false;
        }

        try {
            $rendered = $this->renderer->render($template, $context['variables']);
        } catch (DomainRuleException) {
            return $this->finalizeRetrySend(
                $claimedDelivery->notificationDeliveryId,
                $workerId,
                $leaseToken,
                $claimedDelivery->attemptCount,
                NotificationFailureCategory::TEMPLATE_VIOLATION,
                null,
            );
        }

        $emailMessage = new EmailDeliveryMessage(
            toAddress: $context['recipient']['email'],
            templateKey: $template->key,
            subject: $rendered['subject'],
            bodyText: $rendered['body'],
            idempotencyKey: 'notif-retry:' . $deliveryId . ':' . $claimedDelivery->attemptCount,
        );

        try {
            $receipt = $this->emailPort->send($emailMessage);
        } catch (Throwable $exception) {
            return $this->finalizeRetrySend(
                $claimedDelivery->notificationDeliveryId,
                $workerId,
                $leaseToken,
                $claimedDelivery->attemptCount,
                $this->classifyProviderException($exception),
                null,
            );
        }

        return $this->finalizeRetrySend(
            $claimedDelivery->notificationDeliveryId,
            $workerId,
            $leaseToken,
            $claimedDelivery->attemptCount,
            null,
            $receipt->providerMessageId,
        );
    }

    private function finalizeRetrySend(
        int $deliveryId,
        string $workerId,
        string $leaseToken,
        int $attemptCount,
        ?string $failureCategory,
        ?string $providerMessageId,
    ): bool {
        return $this->transactions->run(function () use (
            $deliveryId,
            $workerId,
            $leaseToken,
            $attemptCount,
            $failureCategory,
            $providerMessageId,
        ): bool {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($failureCategory === null) {
                if (!$this->deliveries->markDelivered(
                    $deliveryId,
                    $workerId,
                    $leaseToken,
                    $providerMessageId,
                    $now,
                )) {
                    throw new DomainRuleException('Delivery finalisation is stale.');
                }
                $this->audit->record(
                    new NotificationAuditPayload(
                        'notification.delivered',
                        'notification_delivery',
                        (string) $deliveryId,
                        next: [
                            'delivery_id' => $deliveryId,
                            'provider_message_id' => $providerMessageId,
                            'status' => NotificationDeliveryStatus::DELIVERED,
                            'attempt_count' => $attemptCount,
                        ],
                    ),
                    'system',
                    null,
                    'worker',
                );

                return true;
            }
            if ($this->retryPolicy->shouldDeadLetter($attemptCount, $failureCategory)) {
                if (!$this->deliveries->markDead(
                    $deliveryId,
                    $workerId,
                    $leaseToken,
                    $failureCategory,
                    $attemptCount,
                    $now,
                )) {
                    throw new DomainRuleException('Delivery finalisation is stale.');
                }
                $this->audit->record(
                    new NotificationAuditPayload(
                        'notification.dead',
                        'notification_delivery',
                        (string) $deliveryId,
                        next: [
                            'delivery_id' => $deliveryId,
                            'failure_category' => $failureCategory,
                            'attempt_count' => $attemptCount,
                            'status' => NotificationDeliveryStatus::DEAD,
                        ],
                    ),
                    'system',
                    null,
                    'worker',
                );

                return true;
            }
            $backoff = $this->retryPolicy->backoffSeconds($attemptCount);
            $next = $now->modify('+' . $backoff . ' seconds');
            if (!$this->deliveries->markFailedRetry(
                $deliveryId,
                $workerId,
                $leaseToken,
                $failureCategory,
                $attemptCount,
                $next,
                $now,
            )) {
                throw new DomainRuleException('Delivery finalisation is stale.');
            }
            $this->audit->record(
                new NotificationAuditPayload(
                    'notification.failed',
                    'notification_delivery',
                    (string) $deliveryId,
                    next: [
                        'delivery_id' => $deliveryId,
                        'failure_category' => $failureCategory,
                        'attempt_count' => $attemptCount,
                        'status' => NotificationDeliveryStatus::FAILED,
                    ],
                ),
                'system',
                null,
                'worker',
            );

            return true;
        });
    }

    private function processOne(OutboxMessage $message, string $workerId): bool
    {
        if (!TransactionalNotificationEventTypes::isTransactional($message->eventType)) {
            return false;
        }

        $template = $this->templates->forEventType($message->eventType);

        try {
            $context = $this->contextResolver->resolve($message);
        } catch (DomainRuleException $exception) {
            $category = $this->mapPrepFailure($exception->getMessage());
            $userId = $this->contextResolver->tryResolveUserId($message);

            return $this->finalizePrepTerminal(
                $message,
                $template->key,
                $template->version,
                $userId,
                $category,
            );
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $delivery = $this->deliveries->ensurePending(
            $message->id,
            $message->eventType,
            $context['user_id'],
            $template->channel,
            $template->key,
            $template->version,
            $context['recipient']['recipient_hash'],
            $context['recipient']['recipient_masked'],
            $now,
        );

        if ($delivery->status === NotificationDeliveryStatus::DELIVERED
            || $delivery->status === NotificationDeliveryStatus::DEAD
        ) {
            return $this->outbox->markPublished(
                $message->id,
                $message->lockedBy,
                $message->claimToken,
                $now,
            );
        }

        $leaseToken = $this->newLeaseToken();
        $claimedDelivery = $this->deliveries->claimForSend(
            $delivery->notificationDeliveryId,
            $workerId,
            $leaseToken,
            $now,
            $this->leaseSeconds,
        );
        if ($claimedDelivery === null) {
            // Another worker owns the delivery; release outbox for retry.
            $this->softFailOutbox($message, NotificationFailureCategory::PROVIDER_TRANSIENT);

            return false;
        }

        $this->audit->record(
            new NotificationAuditPayload(
                'notification.delivery_attempted',
                'notification_delivery',
                (string) $claimedDelivery->notificationDeliveryId,
                previous: ['status' => $delivery->status],
                next: [
                    'delivery_id' => $claimedDelivery->notificationDeliveryId,
                    'outbox_message_id' => $message->id,
                    'user_id' => $claimedDelivery->userId,
                    'channel' => $claimedDelivery->channel,
                    'template_key' => $claimedDelivery->templateKey,
                    'template_version' => $claimedDelivery->templateVersion,
                    'attempt_count' => $claimedDelivery->attemptCount,
                    'status' => NotificationDeliveryStatus::PROCESSING,
                ],
            ),
            'system',
            null,
            'worker',
        );

        try {
            $rendered = $this->renderer->render($template, $context['variables']);
        } catch (DomainRuleException) {
            return $this->finalizeSendOutcome(
                $message,
                $claimedDelivery->notificationDeliveryId,
                $workerId,
                $leaseToken,
                $claimedDelivery->attemptCount,
                NotificationFailureCategory::TEMPLATE_VIOLATION,
                null,
            );
        }

        $idempotencyKey = 'notif:' . $message->id . ':' . $template->channel . ':' . $template->key;
        $emailMessage = new EmailDeliveryMessage(
            toAddress: $context['recipient']['email'],
            templateKey: $template->key,
            subject: $rendered['subject'],
            bodyText: $rendered['body'],
            idempotencyKey: $idempotencyKey,
        );

        try {
            $receipt = $this->emailPort->send($emailMessage);
        } catch (Throwable $exception) {
            $category = $this->classifyProviderException($exception);

            return $this->finalizeSendOutcome(
                $message,
                $claimedDelivery->notificationDeliveryId,
                $workerId,
                $leaseToken,
                $claimedDelivery->attemptCount,
                $category,
                null,
            );
        }

        return $this->finalizeSendOutcome(
            $message,
            $claimedDelivery->notificationDeliveryId,
            $workerId,
            $leaseToken,
            $claimedDelivery->attemptCount,
            null,
            $receipt->providerMessageId,
        );
    }

    private function finalizeSendOutcome(
        OutboxMessage $message,
        int $deliveryId,
        string $workerId,
        string $leaseToken,
        int $attemptCount,
        ?string $failureCategory,
        ?string $providerMessageId,
    ): bool {
        return $this->transactions->run(function () use (
            $message,
            $deliveryId,
            $workerId,
            $leaseToken,
            $attemptCount,
            $failureCategory,
            $providerMessageId,
        ): bool {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($failureCategory === null) {
                if (!$this->deliveries->markDelivered(
                    $deliveryId,
                    $workerId,
                    $leaseToken,
                    $providerMessageId,
                    $now,
                )) {
                    throw new DomainRuleException('Delivery finalisation is stale.');
                }
                if (!$this->outbox->markPublished(
                    $message->id,
                    $message->lockedBy,
                    $message->claimToken,
                    $now,
                )) {
                    throw new DomainRuleException('Outbox finalisation is stale.');
                }
                $this->audit->record(
                    new NotificationAuditPayload(
                        'notification.delivered',
                        'notification_delivery',
                        (string) $deliveryId,
                        next: [
                            'delivery_id' => $deliveryId,
                            'outbox_message_id' => $message->id,
                            'provider_message_id' => $providerMessageId,
                            'status' => NotificationDeliveryStatus::DELIVERED,
                            'attempt_count' => $attemptCount,
                        ],
                    ),
                    'system',
                    null,
                    'worker',
                );

                return true;
            }

            if ($this->retryPolicy->shouldDeadLetter($attemptCount, $failureCategory)) {
                if (!$this->deliveries->markDead(
                    $deliveryId,
                    $workerId,
                    $leaseToken,
                    $failureCategory,
                    $attemptCount,
                    $now,
                )) {
                    throw new DomainRuleException('Delivery finalisation is stale.');
                }
                if (!$this->outbox->markPublished(
                    $message->id,
                    $message->lockedBy,
                    $message->claimToken,
                    $now,
                )) {
                    throw new DomainRuleException('Outbox finalisation is stale.');
                }
                $this->audit->record(
                    new NotificationAuditPayload(
                        'notification.dead',
                        'notification_delivery',
                        (string) $deliveryId,
                        next: [
                            'delivery_id' => $deliveryId,
                            'outbox_message_id' => $message->id,
                            'failure_category' => $failureCategory,
                            'attempt_count' => $attemptCount,
                            'status' => NotificationDeliveryStatus::DEAD,
                        ],
                    ),
                    'system',
                    null,
                    'worker',
                );

                return true;
            }

            $backoff = $this->retryPolicy->backoffSeconds($attemptCount);
            $next = $now->modify('+' . $backoff . ' seconds');
            if (!$this->deliveries->markFailedRetry(
                $deliveryId,
                $workerId,
                $leaseToken,
                $failureCategory,
                $attemptCount,
                $next,
                $now,
            )) {
                throw new DomainRuleException('Delivery finalisation is stale.');
            }
            if (!$this->outbox->markRetryOrDead(
                $message->id,
                $message->lockedBy,
                $message->claimToken,
                $message->attemptCount,
                $this->retryPolicy->maxAttempts() + 10,
                $failureCategory,
                $now,
                $backoff,
            )) {
                throw new DomainRuleException('Outbox finalisation is stale.');
            }
            $this->audit->record(
                new NotificationAuditPayload(
                    'notification.failed',
                    'notification_delivery',
                    (string) $deliveryId,
                    next: [
                        'delivery_id' => $deliveryId,
                        'outbox_message_id' => $message->id,
                        'failure_category' => $failureCategory,
                        'attempt_count' => $attemptCount,
                        'status' => NotificationDeliveryStatus::FAILED,
                    ],
                ),
                'system',
                null,
                'worker',
            );

            return true;
        });
    }

    private function finalizePrepTerminal(
        OutboxMessage $message,
        string $templateKey,
        int $templateVersion,
        ?int $userId,
        string $category,
    ): bool {
        return $this->transactions->run(function () use (
            $message,
            $templateKey,
            $templateVersion,
            $userId,
            $category,
        ): bool {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $deliveryId = null;
            if ($userId !== null && $userId > 0) {
                $delivery = $this->deliveries->ensurePending(
                    $message->id,
                    $message->eventType,
                    $userId,
                    $this->templates->channel(),
                    $templateKey,
                    $templateVersion,
                    hash('sha256', 'unavailable:' . $message->id),
                    '***@unavailable',
                    $now,
                );
                $deliveryId = $delivery->notificationDeliveryId;
                if ($delivery->status !== NotificationDeliveryStatus::DELIVERED
                    && $delivery->status !== NotificationDeliveryStatus::DEAD
                ) {
                    $this->deliveries->markDeadFromPrep($delivery->notificationDeliveryId, $category, $now);
                }
            }
            $ok = $this->outbox->markPublished(
                $message->id,
                $message->lockedBy,
                $message->claimToken,
                $now,
            );
            $this->audit->record(
                new NotificationAuditPayload(
                    'notification.dead',
                    'notification_delivery',
                    $deliveryId !== null ? (string) $deliveryId : (string) $message->id,
                    next: [
                        'delivery_id' => $deliveryId,
                        'outbox_message_id' => $message->id,
                        'failure_category' => $category,
                        'status' => NotificationDeliveryStatus::DEAD,
                    ],
                ),
                'system',
                null,
                'worker',
            );

            return $ok;
        });
    }

    private function softFailOutbox(OutboxMessage $message, string $category): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $backoff = $this->retryPolicy->backoffSeconds($message->attemptCount);
        $this->outbox->markRetryOrDead(
            $message->id,
            $message->lockedBy,
            $message->claimToken,
            $message->attemptCount,
            $this->retryPolicy->maxAttempts() + 10,
            $category,
            $now,
            $backoff,
        );
    }

    private function mapPrepFailure(string $message): string
    {
        if (in_array($message, NotificationFailureCategory::terminal(), true)
            || in_array($message, NotificationFailureCategory::retryable(), true)
        ) {
            return $message;
        }

        return NotificationFailureCategory::CONTEXT_MISSING;
    }

    private function classifyProviderException(Throwable $exception): string
    {
        $class = $exception::class;
        $msg = strtolower($exception->getMessage());
        if (str_contains($msg, 'timeout') || str_contains($class, 'Timeout')) {
            return NotificationFailureCategory::TIMEOUT;
        }
        if (str_contains($msg, 'rate') || str_contains($msg, '429')) {
            return NotificationFailureCategory::RATE_LIMITED;
        }
        if (str_contains($msg, 'network') || str_contains($msg, 'connection')) {
            return NotificationFailureCategory::NETWORK;
        }
        if (str_contains($msg, 'permanent') || str_contains($msg, 'invalid recipient')) {
            return NotificationFailureCategory::PROVIDER_PERMANENT;
        }

        return NotificationFailureCategory::PROVIDER_TRANSIENT;
    }

    private function newLeaseToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
