<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentProvider;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoPaymentRepository implements PaymentRepository
{
    private const COLUMNS = 'payment_id, public_reference, application_id, enrolment_id, user_id, provider, provider_order_id,
        provider_payment_id, base_fee_minor, gst_minor, amount_minor, currency, gst_rate_percent,
        course_version_id, batch_id, fee_override_applied, status, failure_code, failure_category,
        attempt_number, idempotency_key, row_version, successful_marker, initiated_at,
        provider_order_bound_at, authorized_at, captured_at, failed_at, expired_at, reconciled_at,
        reconcile_lease_owner, reconcile_lease_token, reconcile_lease_expires_at,
        created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $paymentId): ?Payment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM payments WHERE payment_id = :id');
        $stmt->execute(['id' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByIdForUpdate(int $paymentId): ?Payment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE payment_id = :id FOR UPDATE',
        );
        $stmt->execute(['id' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByPublicReference(string $publicReference): ?Payment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE public_reference = :ref',
        );
        $stmt->execute(['ref' => $publicReference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByProviderOrderId(string $provider, string $providerOrderId): ?Payment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payments
             WHERE provider = :provider AND provider_order_id = :order_id',
        );
        $stmt->execute([
            'provider' => $provider,
            'order_id' => $providerOrderId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByApplicationId(int $applicationId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payments
             WHERE application_id = :application_id
             ORDER BY attempt_number ASC, payment_id ASC',
        );
        $stmt->execute(['application_id' => $applicationId]);

        $payments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[] = $this->mapRow($row);
        }

        return $payments;
    }

    public function lockAllForApplication(int $applicationId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payments
             WHERE application_id = :application_id
             ORDER BY attempt_number ASC, payment_id ASC
             FOR UPDATE',
        );
        $stmt->execute(['application_id' => $applicationId]);

        $payments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[] = $this->mapRow($row);
        }

        return $payments;
    }

    public function insertCreated(
        int $applicationId,
        int $userId,
        string $publicReference,
        string $provider,
        PaymentAmountSnapshot $snapshot,
        int $attemptNumber,
        string $idempotencyKey,
        DateTimeImmutable $now,
    ): Payment {
        PaymentProvider::assertValid($provider);
        PaymentStatus::assertValid(PaymentStatus::CREATED);

        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO payments (
                public_reference, application_id, enrolment_id, user_id, provider, provider_order_id, provider_payment_id,
                base_fee_minor, gst_minor, amount_minor, currency, gst_rate_percent,
                course_version_id, batch_id, fee_override_applied, status, failure_code, failure_category,
                attempt_number, idempotency_key, row_version, successful_marker, initiated_at,
                provider_order_bound_at, authorized_at, captured_at, failed_at, expired_at, reconciled_at,
                created_at, updated_at
            ) VALUES (
                :public_reference, :application_id, NULL, :user_id, :provider, NULL, NULL,
                :base_fee_minor, :gst_minor, :amount_minor, :currency, :gst_rate_percent,
                :course_version_id, :batch_id, :fee_override_applied, :status, NULL, NULL,
                :attempt_number, :idempotency_key, 1, NULL, :initiated_at,
                NULL, NULL, NULL, NULL, NULL, NULL,
                :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'public_reference' => $publicReference,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'provider' => $provider,
            'base_fee_minor' => $snapshot->baseFeeMinor,
            'gst_minor' => $snapshot->gstMinor,
            'amount_minor' => $snapshot->totalPayableMinor,
            'currency' => $snapshot->currency,
            'gst_rate_percent' => $snapshot->gstRatePercent,
            'course_version_id' => $snapshot->courseVersionId,
            'batch_id' => $snapshot->batchId,
            'fee_override_applied' => $snapshot->feeOverrideApplied,
            'status' => PaymentStatus::CREATED,
            'attempt_number' => $attemptNumber,
            'idempotency_key' => $idempotencyKey,
            'initiated_at' => $nowStr,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        $paymentId = (int) $pdo->lastInsertId();
        $payment = $this->findById($paymentId);
        if ($payment === null) {
            throw new \RuntimeException('Failed to load inserted payment.');
        }

        return $payment;
    }

    public function bindProviderOrder(
        int $paymentId,
        string $providerOrderId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE payments
             SET provider_order_id = :provider_order_id,
                 provider_order_bound_at = :bound_at,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE payment_id = :payment_id
               AND status = :created
               AND provider_order_id IS NULL
               AND row_version = :row_version',
        );
        $stmt->execute([
            'provider_order_id' => $providerOrderId,
            'bound_at' => $nowStr,
            'updated_at' => $nowStr,
            'payment_id' => $paymentId,
            'created' => PaymentStatus::CREATED,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function applyTransition(
        int $paymentId,
        string $fromStatus,
        string $toStatus,
        int $expectedRowVersion,
        DateTimeImmutable $now,
        ?string $failureCode = null,
        ?string $failureCategory = null,
        ?string $providerPaymentId = null,
        ?int $successfulMarker = null,
    ): bool {
        PaymentStatus::assertValid($fromStatus);
        PaymentStatus::assertValid($toStatus);

        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $failedAt = $toStatus === PaymentStatus::FAILED ? $nowStr : null;
        $expiredAt = $toStatus === PaymentStatus::EXPIRED ? $nowStr : null;
        $capturedAt = $toStatus === PaymentStatus::SUCCESSFUL ? $nowStr : null;
        $reconciledAt = $toStatus === PaymentStatus::RECONCILIATION_PENDING ? $nowStr : null;

        $stmt = $pdo->prepare(
            'UPDATE payments
             SET status = :to_status,
                 failure_code = COALESCE(:failure_code, failure_code),
                 failure_category = COALESCE(:failure_category, failure_category),
                 provider_payment_id = COALESCE(:provider_payment_id, provider_payment_id),
                 successful_marker = COALESCE(:successful_marker, successful_marker),
                 failed_at = COALESCE(:failed_at, failed_at),
                 expired_at = COALESCE(:expired_at, expired_at),
                 captured_at = COALESCE(:captured_at, captured_at),
                 reconciled_at = COALESCE(:reconciled_at, reconciled_at),
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE payment_id = :payment_id
               AND status = :from_status
               AND row_version = :row_version',
        );
        $stmt->execute([
            'to_status' => $toStatus,
            'failure_code' => $failureCode,
            'failure_category' => $failureCategory,
            'provider_payment_id' => $providerPaymentId,
            'successful_marker' => $successfulMarker,
            'failed_at' => $failedAt,
            'expired_at' => $expiredAt,
            'captured_at' => $capturedAt,
            'reconciled_at' => $reconciledAt,
            'updated_at' => $nowStr,
            'payment_id' => $paymentId,
            'from_status' => $fromStatus,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function bindEnrolmentId(
        int $paymentId,
        int $enrolmentId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE payments
             SET enrolment_id = :enrolment_id,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE payment_id = :payment_id
               AND enrolment_id IS NULL
               AND row_version = :row_version',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'updated_at' => $nowStr,
            'payment_id' => $paymentId,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function claimForReconciliation(
        string $workerId,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $pendingStaleSeconds,
        int $limit,
    ): array {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $leaseExpires = $now->modify('+' . $leaseSeconds . ' seconds')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
        $staleBefore = $now->modify('-' . max(0, $pendingStaleSeconds) . ' seconds')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
        $limit = max(1, min(50, $limit));

        $select = $pdo->prepare(
            'SELECT payment_id FROM payments
             WHERE (
                (status = :pending AND provider_order_bound_at IS NOT NULL AND provider_order_bound_at <= :stale_before)
                OR status = :reconciliation_pending
             )
             AND (
                reconcile_lease_expires_at IS NULL
                OR reconcile_lease_expires_at < :now
             )
             ORDER BY payment_id ASC
             LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED',
        );
        $select->execute([
            'pending' => PaymentStatus::PENDING,
            'reconciliation_pending' => PaymentStatus::RECONCILIATION_PENDING,
            'stale_before' => $staleBefore,
            'now' => $nowStr,
        ]);
        $ids = array_map(static fn (array $r): int => (int) $r['payment_id'], $select->fetchAll(PDO::FETCH_ASSOC));
        if ($ids === []) {
            return [];
        }

        $claimed = [];
        foreach ($ids as $paymentId) {
            $token = $this->newLeaseToken();
            $update = $pdo->prepare(
                'UPDATE payments
                 SET reconcile_lease_owner = :owner,
                     reconcile_lease_token = :token,
                     reconcile_lease_expires_at = :expires,
                     updated_at = :updated_at
                 WHERE payment_id = :payment_id
                   AND (
                     reconcile_lease_expires_at IS NULL
                     OR reconcile_lease_expires_at < :now
                   )',
            );
            $update->execute([
                'owner' => $workerId,
                'token' => $token,
                'expires' => $leaseExpires,
                'updated_at' => $nowStr,
                'payment_id' => $paymentId,
                'now' => $nowStr,
            ]);
            if ($update->rowCount() !== 1) {
                continue;
            }
            $payment = $this->findById($paymentId);
            if ($payment !== null) {
                $claimed[] = $payment;
            }
        }

        return $claimed;
    }

    public function hasActiveReconcileLease(
        int $paymentId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM payments
             WHERE payment_id = :payment_id
               AND reconcile_lease_owner = :owner
               AND reconcile_lease_token = :token
               AND reconcile_lease_expires_at IS NOT NULL
               AND reconcile_lease_expires_at >= :now',
        );
        $stmt->execute([
            'payment_id' => $paymentId,
            'owner' => $leaseOwner,
            'token' => $leaseToken,
            'now' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function clearReconcileLease(
        int $paymentId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE payments
             SET reconcile_lease_owner = NULL,
                 reconcile_lease_token = NULL,
                 reconcile_lease_expires_at = NULL,
                 updated_at = :updated_at
             WHERE payment_id = :payment_id
               AND reconcile_lease_owner = :owner
               AND reconcile_lease_token = :token',
        );
        $stmt->execute([
            'updated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'payment_id' => $paymentId,
            'owner' => $leaseOwner,
            'token' => $leaseToken,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function findSuccessfulMarkerForApplication(int $applicationId): ?Payment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payments
             WHERE application_id = :application_id AND successful_marker = 1
             LIMIT 1',
        );
        $stmt->execute(['application_id' => $applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listForFinance(
        ?string $status,
        ?string $publicReference,
        ?string $providerOrderId,
        ?int $applicationId,
        int $limit,
        int $offset,
    ): array {
        $pdo = $this->connections->connection();
        [$where, $params] = $this->financeFilters($status, $publicReference, $providerOrderId, $applicationId);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT ' . self::COLUMNS . ' FROM payments'
            . $where
            . ' ORDER BY created_at DESC, payment_id DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $payments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[] = $this->mapRow($row);
        }

        return $payments;
    }

    public function countForFinance(
        ?string $status,
        ?string $publicReference,
        ?string $providerOrderId,
        ?int $applicationId,
    ): int {
        $pdo = $this->connections->connection();
        [$where, $params] = $this->financeFilters($status, $publicReference, $providerOrderId, $applicationId);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payments' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function financeFilters(
        ?string $status,
        ?string $publicReference,
        ?string $providerOrderId,
        ?int $applicationId,
    ): array {
        $clauses = [];
        $params = [];

        if ($status !== null && $status !== '') {
            PaymentStatus::assertValid($status);
            $clauses[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($publicReference !== null && $publicReference !== '') {
            $clauses[] = 'public_reference = :public_reference';
            $params['public_reference'] = $publicReference;
        }
        if ($providerOrderId !== null && $providerOrderId !== '') {
            $clauses[] = 'provider_order_id = :provider_order_id';
            $params['provider_order_id'] = $providerOrderId;
        }
        if ($applicationId !== null) {
            $clauses[] = 'application_id = :application_id';
            $params['application_id'] = $applicationId;
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
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
    private function mapRow(array $row): Payment
    {
        $utc = new DateTimeZone('UTC');

        return new Payment(
            paymentId: (int) $row['payment_id'],
            publicReference: (string) $row['public_reference'],
            applicationId: (int) $row['application_id'],
            enrolmentId: $row['enrolment_id'] === null ? null : (int) $row['enrolment_id'],
            userId: (int) $row['user_id'],
            provider: (string) $row['provider'],
            providerOrderId: $row['provider_order_id'] === null ? null : (string) $row['provider_order_id'],
            providerPaymentId: $row['provider_payment_id'] === null ? null : (string) $row['provider_payment_id'],
            baseFeeMinor: (int) $row['base_fee_minor'],
            gstMinor: (int) $row['gst_minor'],
            amountMinor: (int) $row['amount_minor'],
            currency: (string) $row['currency'],
            gstRatePercent: (string) $row['gst_rate_percent'],
            courseVersionId: (int) $row['course_version_id'],
            batchId: (int) $row['batch_id'],
            feeOverrideApplied: $row['fee_override_applied'] === null ? null : (string) $row['fee_override_applied'],
            status: (string) $row['status'],
            failureCode: $row['failure_code'] === null ? null : (string) $row['failure_code'],
            failureCategory: $row['failure_category'] === null ? null : (string) $row['failure_category'],
            attemptNumber: (int) $row['attempt_number'],
            idempotencyKey: (string) $row['idempotency_key'],
            rowVersion: (int) $row['row_version'],
            successfulMarker: $row['successful_marker'] === null ? null : (int) $row['successful_marker'],
            initiatedAt: new DateTimeImmutable((string) $row['initiated_at'], $utc),
            providerOrderBoundAt: $row['provider_order_bound_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['provider_order_bound_at'], $utc),
            authorizedAt: $row['authorized_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['authorized_at'], $utc),
            capturedAt: $row['captured_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['captured_at'], $utc),
            failedAt: $row['failed_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['failed_at'], $utc),
            expiredAt: $row['expired_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['expired_at'], $utc),
            reconciledAt: $row['reconciled_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['reconciled_at'], $utc),
            reconcileLeaseOwner: $row['reconcile_lease_owner'] === null
                ? null
                : (string) $row['reconcile_lease_owner'],
            reconcileLeaseToken: $row['reconcile_lease_token'] === null
                ? null
                : (string) $row['reconcile_lease_token'],
            reconcileLeaseExpiresAt: $row['reconcile_lease_expires_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['reconcile_lease_expires_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
