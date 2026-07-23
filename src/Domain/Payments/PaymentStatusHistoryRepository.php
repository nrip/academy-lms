<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use DateTimeImmutable;

final class PaymentStatusHistoryWrite
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $applicationId,
        public readonly string $statusBefore,
        public readonly string $statusAfter,
        public readonly string $source,
        public readonly ?string $providerEventReference,
        public readonly ?string $reason,
        public readonly ?string $failureCategory,
        public readonly ?int $actorUserId,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}

interface PaymentStatusHistoryRepository
{
    public function append(PaymentStatusHistoryWrite $row): void;

    /**
     * @return list<array{
     *   history_id: int,
     *   payment_id: int,
     *   application_id: int,
     *   status_before: string,
     *   status_after: string,
     *   source: string,
     *   provider_event_reference: ?string,
     *   reason: ?string,
     *   failure_category: ?string,
     *   actor_user_id: ?int,
     *   created_at: string
     * }>
     */
    public function listByPaymentId(int $paymentId): array;
}
