<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\PaymentStatusHistoryWrite;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeZone;
use PDO;

final class PdoPaymentStatusHistoryRepository implements PaymentStatusHistoryRepository
{
    private const COLUMNS = 'history_id, payment_id, application_id, status_before, status_after, source,
        provider_event_reference, reason, failure_category, actor_user_id, created_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function append(PaymentStatusHistoryWrite $row): void
    {
        $pdo = $this->connections->connection();
        $createdStr = $row->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO payment_status_history (
                payment_id, application_id, status_before, status_after, source,
                provider_event_reference, reason, failure_category, actor_user_id, created_at
            ) VALUES (
                :payment_id, :application_id, :status_before, :status_after, :source,
                :provider_event_reference, :reason, :failure_category, :actor_user_id, :created_at
            )',
        );
        $stmt->execute([
            'payment_id' => $row->paymentId,
            'application_id' => $row->applicationId,
            'status_before' => $row->statusBefore,
            'status_after' => $row->statusAfter,
            'source' => $row->source,
            'provider_event_reference' => $row->providerEventReference,
            'reason' => $row->reason,
            'failure_category' => $row->failureCategory,
            'actor_user_id' => $row->actorUserId,
            'created_at' => $createdStr,
        ]);
    }

    public function listByPaymentId(int $paymentId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payment_status_history
             WHERE payment_id = :payment_id
             ORDER BY created_at ASC, history_id ASC',
        );
        $stmt->execute(['payment_id' => $paymentId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'history_id' => (int) $row['history_id'],
                'payment_id' => (int) $row['payment_id'],
                'application_id' => (int) $row['application_id'],
                'status_before' => (string) $row['status_before'],
                'status_after' => (string) $row['status_after'],
                'source' => (string) $row['source'],
                'provider_event_reference' => $row['provider_event_reference'] === null
                    ? null
                    : (string) $row['provider_event_reference'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'failure_category' => $row['failure_category'] === null
                    ? null
                    : (string) $row['failure_category'],
                'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $rows;
    }
}
