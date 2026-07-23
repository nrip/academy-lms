<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Payments\PaymentProvider;
use Academy\Domain\Payments\Webhook\NormalizedWebhookEvent;
use Academy\Domain\Payments\Webhook\PaymentWebhookEvent;
use Academy\Domain\Payments\Webhook\PaymentWebhookEventRepository;
use Academy\Domain\Payments\Webhook\WebhookProcessingStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class PdoPaymentWebhookEventRepository implements PaymentWebhookEventRepository
{
    private const COLUMNS = 'webhook_event_id, provider, provider_event_id, event_type, provider_order_id,
        provider_payment_id, payload_digest, amount_minor, currency, provider_status, captured_flag,
        failure_code, failure_category, occurred_at, signature_verified_at, received_at, processing_status,
        attempt_count, next_attempt_at, failure_category_processing, lease_owner, lease_token, lease_expires_at,
        row_version, processed_at, ignore_reason, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insertIdempotent(
        NormalizedWebhookEvent $normalized,
        DateTimeImmutable $signatureVerifiedAt,
        DateTimeImmutable $receivedAt,
    ): array {
        PaymentProvider::assertValid($normalized->provider);
        WebhookProcessingStatus::assertValid(WebhookProcessingStatus::RECEIVED);

        $pdo = $this->connections->connection();
        $utc = new DateTimeZone('UTC');
        $verifiedStr = $signatureVerifiedAt->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $receivedStr = $receivedAt->setTimezone($utc)->format('Y-m-d H:i:s.u');

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payment_webhook_events (
                    provider, provider_event_id, event_type, provider_order_id, provider_payment_id,
                    payload_digest, amount_minor, currency, provider_status, captured_flag,
                    failure_code, failure_category, occurred_at, signature_verified_at, received_at,
                    processing_status, attempt_count, next_attempt_at, failure_category_processing,
                    lease_owner, lease_token, lease_expires_at, row_version, processed_at, ignore_reason,
                    created_at, updated_at
                ) VALUES (
                    :provider, :provider_event_id, :event_type, :provider_order_id, :provider_payment_id,
                    :payload_digest, :amount_minor, :currency, :provider_status, :captured_flag,
                    :failure_code, :failure_category, :occurred_at, :signature_verified_at, :received_at,
                    :processing_status, 0, :next_attempt_at, NULL,
                    NULL, NULL, NULL, 1, NULL, NULL,
                    :created_at, :updated_at
                )',
            );
            $stmt->execute([
                'provider' => $normalized->provider,
                'provider_event_id' => $normalized->providerEventId,
                'event_type' => $normalized->eventType,
                'provider_order_id' => $normalized->providerOrderId,
                'provider_payment_id' => $normalized->providerPaymentId,
                'payload_digest' => $normalized->payloadDigest,
                'amount_minor' => $normalized->amountMinor,
                'currency' => $normalized->currency,
                'provider_status' => $normalized->providerStatus,
                'captured_flag' => $normalized->captured === null ? null : ($normalized->captured ? 1 : 0),
                'failure_code' => $normalized->failureCode,
                'failure_category' => $normalized->failureCategory,
                'occurred_at' => $normalized->occurredAt?->setTimezone($utc)->format('Y-m-d H:i:s.u'),
                'signature_verified_at' => $verifiedStr,
                'received_at' => $receivedStr,
                'processing_status' => WebhookProcessingStatus::RECEIVED,
                'next_attempt_at' => $receivedStr,
                'created_at' => $receivedStr,
                'updated_at' => $receivedStr,
            ]);

            $event = $this->findById((int) $pdo->lastInsertId());
            if ($event === null) {
                throw new \RuntimeException('Failed to load inserted webhook event.');
            }

            return ['event' => $event, 'created' => true];
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $existing = $this->findByProviderEvent($normalized->provider, $normalized->providerEventId);
            if ($existing === null) {
                throw $exception;
            }

            return ['event' => $existing, 'created' => false];
        }
    }

    public function claimPending(
        string $workerId,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $limit,
    ): array {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $leaseExpires = $now->modify('+' . $leaseSeconds . ' seconds')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
        $limit = max(1, min(50, $limit));

        $select = $pdo->prepare(
            'SELECT webhook_event_id FROM payment_webhook_events
             WHERE (
                (processing_status = :received AND next_attempt_at IS NOT NULL AND next_attempt_at <= :now1)
                OR (processing_status = :failed AND next_attempt_at IS NOT NULL AND next_attempt_at <= :now2)
                OR (processing_status = :processing AND lease_expires_at IS NOT NULL AND lease_expires_at < :now3)
             )
             ORDER BY webhook_event_id ASC
             LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED',
        );
        $select->execute([
            'received' => WebhookProcessingStatus::RECEIVED,
            'failed' => WebhookProcessingStatus::FAILED,
            'processing' => WebhookProcessingStatus::PROCESSING,
            'now1' => $nowStr,
            'now2' => $nowStr,
            'now3' => $nowStr,
        ]);
        $ids = array_map(
            static fn (array $r): int => (int) $r['webhook_event_id'],
            $select->fetchAll(PDO::FETCH_ASSOC),
        );
        if ($ids === []) {
            return [];
        }

        $claimed = [];
        foreach ($ids as $id) {
            $token = $this->newLeaseToken();
            $update = $pdo->prepare(
                'UPDATE payment_webhook_events
                 SET processing_status = :processing,
                     attempt_count = attempt_count + 1,
                     lease_owner = :owner,
                     lease_token = :token,
                     lease_expires_at = :expires,
                     row_version = row_version + 1,
                     updated_at = :updated_at
                 WHERE webhook_event_id = :id',
            );
            $update->execute([
                'processing' => WebhookProcessingStatus::PROCESSING,
                'owner' => $workerId,
                'token' => $token,
                'expires' => $leaseExpires,
                'updated_at' => $nowStr,
                'id' => $id,
            ]);
            $event = $this->findById($id);
            if ($event !== null) {
                $claimed[] = $event;
            }
        }

        return $claimed;
    }

    public function markProcessed(
        int $webhookEventId,
        string $leaseOwner,
        string $leaseToken,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        return $this->finalize(
            $webhookEventId,
            $leaseOwner,
            $leaseToken,
            $expectedRowVersion,
            $now,
            WebhookProcessingStatus::PROCESSED,
            null,
            null,
        );
    }

    public function markIgnored(
        int $webhookEventId,
        string $leaseOwner,
        string $leaseToken,
        int $expectedRowVersion,
        string $ignoreReason,
        DateTimeImmutable $now,
    ): bool {
        return $this->finalize(
            $webhookEventId,
            $leaseOwner,
            $leaseToken,
            $expectedRowVersion,
            $now,
            WebhookProcessingStatus::IGNORED,
            $ignoreReason,
            null,
        );
    }

    public function markRetryOrDead(
        int $webhookEventId,
        string $leaseOwner,
        string $leaseToken,
        int $expectedRowVersion,
        string $failureCategory,
        DateTimeImmutable $now,
        int $maxAttempts,
        int $backoffBaseSeconds,
        int $backoffCapSeconds,
    ): bool {
        $event = $this->findById($webhookEventId);
        if ($event === null) {
            return false;
        }

        $dead = $event->attemptCount >= $maxAttempts;
        $status = $dead ? WebhookProcessingStatus::DEAD : WebhookProcessingStatus::FAILED;
        $delay = min(
            $backoffCapSeconds,
            $backoffBaseSeconds * (2 ** max(0, $event->attemptCount - 1)),
        );
        $nextAttempt = $dead ? null : $now->modify('+' . $delay . ' seconds');

        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE payment_webhook_events
             SET processing_status = :status,
                 failure_category_processing = :failure_category,
                 next_attempt_at = :next_attempt_at,
                 lease_owner = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE webhook_event_id = :id
               AND lease_owner = :owner
               AND lease_token = :token
               AND row_version = :row_version
               AND processing_status = :processing',
        );
        $stmt->execute([
            'status' => $status,
            'failure_category' => $failureCategory,
            'next_attempt_at' => $nextAttempt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'updated_at' => $nowStr,
            'id' => $webhookEventId,
            'owner' => $leaseOwner,
            'token' => $leaseToken,
            'row_version' => $expectedRowVersion,
            'processing' => WebhookProcessingStatus::PROCESSING,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function findById(int $webhookEventId): ?PaymentWebhookEvent
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payment_webhook_events WHERE webhook_event_id = :id',
        );
        $stmt->execute(['id' => $webhookEventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listForFinance(?string $processingStatus, int $limit, int $offset): array
    {
        $pdo = $this->connections->connection();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $params = [];
        $where = '';
        if ($processingStatus !== null && $processingStatus !== '') {
            WebhookProcessingStatus::assertValid($processingStatus);
            $where = ' WHERE processing_status = :status';
            $params['status'] = $processingStatus;
        }
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payment_webhook_events'
            . $where
            . ' ORDER BY received_at DESC, webhook_event_id DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
        );
        $stmt->execute($params);
        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = $this->mapRow($row);
        }

        return $events;
    }

    public function countForFinance(?string $processingStatus): int
    {
        $pdo = $this->connections->connection();
        $params = [];
        $where = '';
        if ($processingStatus !== null && $processingStatus !== '') {
            WebhookProcessingStatus::assertValid($processingStatus);
            $where = ' WHERE processing_status = :status';
            $params['status'] = $processingStatus;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_webhook_events' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function findByProviderEvent(string $provider, string $providerEventId): ?PaymentWebhookEvent
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payment_webhook_events
             WHERE provider = :provider AND provider_event_id = :provider_event_id',
        );
        $stmt->execute([
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    private function finalize(
        int $webhookEventId,
        string $leaseOwner,
        string $leaseToken,
        int $expectedRowVersion,
        DateTimeImmutable $now,
        string $status,
        ?string $ignoreReason,
        ?string $failureCategory,
    ): bool {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE payment_webhook_events
             SET processing_status = :status,
                 ignore_reason = COALESCE(:ignore_reason, ignore_reason),
                 failure_category_processing = COALESCE(:failure_category, failure_category_processing),
                 processed_at = :processed_at,
                 lease_owner = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 next_attempt_at = NULL,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE webhook_event_id = :id
               AND lease_owner = :owner
               AND lease_token = :token
               AND row_version = :row_version
               AND processing_status = :processing',
        );
        $stmt->execute([
            'status' => $status,
            'ignore_reason' => $ignoreReason,
            'failure_category' => $failureCategory,
            'processed_at' => $nowStr,
            'updated_at' => $nowStr,
            'id' => $webhookEventId,
            'owner' => $leaseOwner,
            'token' => $leaseToken,
            'row_version' => $expectedRowVersion,
            'processing' => WebhookProcessingStatus::PROCESSING,
        ]);

        return $stmt->rowCount() === 1;
    }

    private function newLeaseToken(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): PaymentWebhookEvent
    {
        $utc = new DateTimeZone('UTC');

        return new PaymentWebhookEvent(
            webhookEventId: (int) $row['webhook_event_id'],
            provider: (string) $row['provider'],
            providerEventId: (string) $row['provider_event_id'],
            eventType: (string) $row['event_type'],
            providerOrderId: $row['provider_order_id'] === null ? null : (string) $row['provider_order_id'],
            providerPaymentId: $row['provider_payment_id'] === null ? null : (string) $row['provider_payment_id'],
            payloadDigest: (string) $row['payload_digest'],
            amountMinor: $row['amount_minor'] === null ? null : (int) $row['amount_minor'],
            currency: $row['currency'] === null ? null : (string) $row['currency'],
            providerStatus: $row['provider_status'] === null ? null : (string) $row['provider_status'],
            capturedFlag: $row['captured_flag'] === null ? null : (int) $row['captured_flag'],
            failureCode: $row['failure_code'] === null ? null : (string) $row['failure_code'],
            failureCategory: $row['failure_category'] === null ? null : (string) $row['failure_category'],
            occurredAt: $row['occurred_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['occurred_at'], $utc),
            signatureVerifiedAt: new DateTimeImmutable((string) $row['signature_verified_at'], $utc),
            receivedAt: new DateTimeImmutable((string) $row['received_at'], $utc),
            processingStatus: (string) $row['processing_status'],
            attemptCount: (int) $row['attempt_count'],
            nextAttemptAt: $row['next_attempt_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['next_attempt_at'], $utc),
            failureCategoryProcessing: $row['failure_category_processing'] === null
                ? null
                : (string) $row['failure_category_processing'],
            leaseOwner: $row['lease_owner'] === null ? null : (string) $row['lease_owner'],
            leaseToken: $row['lease_token'] === null ? null : (string) $row['lease_token'],
            leaseExpiresAt: $row['lease_expires_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['lease_expires_at'], $utc),
            rowVersion: (int) $row['row_version'],
            processedAt: $row['processed_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['processed_at'], $utc),
            ignoreReason: $row['ignore_reason'] === null ? null : (string) $row['ignore_reason'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
