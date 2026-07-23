<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Payments;

use Academy\Application\Audit\AuditService;
use Academy\Application\Payments\SuccessfulPaymentAcceptanceService;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Audit\AuditRecord;
use Academy\Domain\Audit\AuditWriter;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Learning\BatchCapacityPolicy;
use Academy\Domain\Learning\Enrolment;
use Academy\Domain\Learning\EnrolmentFactory;
use Academy\Domain\Learning\EnrolmentPublicReferenceGenerator;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\EnrolmentStatusHistoryRepository;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Payments\GatewayPaymentResult;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\PaymentStatusHistoryWrite;
use Academy\Domain\Payments\SuccessfulPaymentAcceptancePolicy;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SuccessfulPaymentAcceptanceRollbackTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('PAYMENTS_FAKE_GATEWAY=1');
        $_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    protected function tearDown(): void
    {
        putenv('PAYMENTS_FAKE_GATEWAY');
        unset($_ENV['PAYMENTS_FAKE_GATEWAY'], $_SERVER['PAYMENTS_FAKE_GATEWAY']);
        parent::tearDown();
    }

    /**
     * @return list<array{0: string}>
     */
    public static function failurePoints(): array
    {
        return [
            ['after_successful_marker'],
            ['after_payment_history'],
            ['after_application_transition'],
            ['after_enrolment_insert'],
            ['after_outbox'],
            ['after_audit'],
        ];
    }

    #[DataProvider('failurePoints')]
    public function testForcedFailureRollsBackAcceptanceAtomically(string $failAt): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(\Academy\Application\Payments\PaymentCheckoutService::class);
        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $pdo = DatabaseTestCase::pdo();
        $occupiedBefore = $this->occupiedSeats($fixture['batch_id']);
        $historyBefore = (int) $pdo->query('SELECT COUNT(*) FROM payment_status_history')->fetchColumn();
        $auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $outboxBefore = (int) $pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn();

        $service = $this->buildService($container, $failAt);
        $provider = new GatewayPaymentResult(
            providerPaymentId: 'pay_roll_' . $payment->paymentId,
            providerOrderId: (string) $payment->providerOrderId,
            amountMinor: $payment->amountMinor,
            currency: $payment->currency,
            providerStatus: 'captured',
            captured: true,
        );

        try {
            $container->get(TransactionManager::class)->run(
                static fn () => $service->accept(
                    $payment->paymentId,
                    $provider,
                    'rollback_test',
                    'evt_roll_' . $payment->paymentId,
                ),
            );
            self::fail('Expected forced failure for ' . $failAt);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('forced failure:', $exception->getMessage());
        }

        $stmt = $pdo->prepare('SELECT status, successful_marker FROM payments WHERE payment_id = ?');
        $stmt->execute([$payment->paymentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(PaymentStatus::PENDING, $row['status']);
        self::assertNull($row['successful_marker']);

        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::PAYMENT_PENDING, $stmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(0, (int) $stmt->fetchColumn());

        self::assertSame($occupiedBefore, $this->occupiedSeats($fixture['batch_id']));
        self::assertSame($historyBefore, (int) $pdo->query('SELECT COUNT(*) FROM payment_status_history')->fetchColumn());
        self::assertSame($auditBefore, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        self::assertSame($outboxBefore, (int) $pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    private function occupiedSeats(int $batchId): int
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM enrolments
                 WHERE batch_id = ? AND lifecycle_status IN (\'scheduled\', \'active\'))
                +
                (SELECT COUNT(*) FROM applications a
                 LEFT JOIN enrolments e ON e.application_id = a.application_id
                 WHERE a.batch_id = ? AND a.status = \'admitted\' AND e.enrolment_id IS NULL)
            )',
        );
        $stmt->execute([$batchId, $batchId]);

        return (int) $stmt->fetchColumn();
    }

    private function buildService(\Psr\Container\ContainerInterface $container, string $failAt): SuccessfulPaymentAcceptanceService
    {
        $payments = $container->get(PaymentRepository::class);
        $applications = $container->get(ApplicationRepository::class);
        $enrolments = $container->get(EnrolmentRepository::class);
        $paymentHistory = $container->get(PaymentStatusHistoryRepository::class);
        $outbox = $container->get(OutboxWriter::class);
        $audit = $container->get(AuditService::class);

        if ($failAt === 'after_successful_marker') {
            $payments = new class ($payments) implements PaymentRepository {
                public function __construct(private PaymentRepository $inner)
                {
                }

                public function findById(int $paymentId): ?\Academy\Domain\Payments\Payment
                {
                    return $this->inner->findById($paymentId);
                }

                public function findByIdForUpdate(int $paymentId): ?\Academy\Domain\Payments\Payment
                {
                    return $this->inner->findByIdForUpdate($paymentId);
                }

                public function findByPublicReference(string $publicReference): ?\Academy\Domain\Payments\Payment
                {
                    return $this->inner->findByPublicReference($publicReference);
                }

                public function findByProviderOrderId(string $provider, string $providerOrderId): ?\Academy\Domain\Payments\Payment
                {
                    return $this->inner->findByProviderOrderId($provider, $providerOrderId);
                }

                public function listByApplicationId(int $applicationId): array
                {
                    return $this->inner->listByApplicationId($applicationId);
                }

                public function lockAllForApplication(int $applicationId): array
                {
                    return $this->inner->lockAllForApplication($applicationId);
                }

                public function insertCreated(
                    int $applicationId,
                    int $userId,
                    string $publicReference,
                    string $provider,
                    \Academy\Domain\Payments\PaymentAmountSnapshot $snapshot,
                    int $attemptNumber,
                    string $idempotencyKey,
                    DateTimeImmutable $now,
                ): \Academy\Domain\Payments\Payment {
                    return $this->inner->insertCreated(
                        $applicationId,
                        $userId,
                        $publicReference,
                        $provider,
                        $snapshot,
                        $attemptNumber,
                        $idempotencyKey,
                        $now,
                    );
                }

                public function bindProviderOrder(
                    int $paymentId,
                    string $providerOrderId,
                    int $expectedRowVersion,
                    DateTimeImmutable $now,
                ): bool {
                    return $this->inner->bindProviderOrder($paymentId, $providerOrderId, $expectedRowVersion, $now);
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
                    $ok = $this->inner->applyTransition(
                        $paymentId,
                        $fromStatus,
                        $toStatus,
                        $expectedRowVersion,
                        $now,
                        $failureCode,
                        $failureCategory,
                        $providerPaymentId,
                        $successfulMarker,
                    );
                    if ($ok && $toStatus === PaymentStatus::SUCCESSFUL && $successfulMarker === 1) {
                        throw new RuntimeException('forced failure: after_successful_marker');
                    }

                    return $ok;
                }

                public function bindEnrolmentId(
                    int $paymentId,
                    int $enrolmentId,
                    int $expectedRowVersion,
                    DateTimeImmutable $now,
                ): bool {
                    return $this->inner->bindEnrolmentId($paymentId, $enrolmentId, $expectedRowVersion, $now);
                }

                public function claimForReconciliation(
                    string $workerId,
                    DateTimeImmutable $now,
                    int $leaseSeconds,
                    int $pendingStaleSeconds,
                    int $limit,
                ): array {
                    return $this->inner->claimForReconciliation(
                        $workerId,
                        $now,
                        $leaseSeconds,
                        $pendingStaleSeconds,
                        $limit,
                    );
                }

                public function hasActiveReconcileLease(
                    int $paymentId,
                    string $leaseOwner,
                    string $leaseToken,
                    DateTimeImmutable $now,
                ): bool {
                    return $this->inner->hasActiveReconcileLease($paymentId, $leaseOwner, $leaseToken, $now);
                }

                public function clearReconcileLease(
                    int $paymentId,
                    string $leaseOwner,
                    string $leaseToken,
                    DateTimeImmutable $now,
                ): bool {
                    return $this->inner->clearReconcileLease($paymentId, $leaseOwner, $leaseToken, $now);
                }

                public function findSuccessfulMarkerForApplication(int $applicationId): ?\Academy\Domain\Payments\Payment
                {
                    return $this->inner->findSuccessfulMarkerForApplication($applicationId);
                }

                public function listForFinance(
                    ?string $status,
                    ?string $publicReference,
                    ?string $providerOrderId,
                    ?int $applicationId,
                    int $limit,
                    int $offset,
                ): array {
                    return $this->inner->listForFinance(
                        $status,
                        $publicReference,
                        $providerOrderId,
                        $applicationId,
                        $limit,
                        $offset,
                    );
                }

                public function countForFinance(
                    ?string $status,
                    ?string $publicReference,
                    ?string $providerOrderId,
                    ?int $applicationId,
                ): int {
                    return $this->inner->countForFinance(
                        $status,
                        $publicReference,
                        $providerOrderId,
                        $applicationId,
                    );
                }
            };
        }

        if ($failAt === 'after_payment_history') {
            $paymentHistory = new class ($paymentHistory) implements PaymentStatusHistoryRepository {
                public function __construct(private PaymentStatusHistoryRepository $inner)
                {
                }

                public function append(PaymentStatusHistoryWrite $row): void
                {
                    $this->inner->append($row);
                    throw new RuntimeException('forced failure: after_payment_history');
                }

                public function listByPaymentId(int $paymentId): array
                {
                    return $this->inner->listByPaymentId($paymentId);
                }
            };
        }

        if ($failAt === 'after_application_transition') {
            $applications = new class ($applications) implements ApplicationRepository {
                public function __construct(private ApplicationRepository $inner)
                {
                }

                public function findById(int $applicationId): ?\Academy\Domain\Admissions\Application
                {
                    return $this->inner->findById($applicationId);
                }

                public function findByIdForUpdate(int $applicationId): ?\Academy\Domain\Admissions\Application
                {
                    return $this->inner->findByIdForUpdate($applicationId);
                }

                public function findByUserAndBatch(int $userId, int $batchId): ?\Academy\Domain\Admissions\Application
                {
                    return $this->inner->findByUserAndBatch($userId, $batchId);
                }

                public function insertDraft(
                    int $userId,
                    int $courseVersionId,
                    int $batchId,
                    DateTimeImmutable $now,
                ): \Academy\Domain\Admissions\Application {
                    return $this->inner->insertDraft($userId, $courseVersionId, $batchId, $now);
                }

                public function updateDeclaration(
                    int $applicationId,
                    string $declarationVersion,
                    DateTimeImmutable $acceptedAt,
                    int $expectedStateVersion,
                    DateTimeImmutable $now,
                ): bool {
                    return $this->inner->updateDeclaration(
                        $applicationId,
                        $declarationVersion,
                        $acceptedAt,
                        $expectedStateVersion,
                        $now,
                    );
                }

                public function applyTransition(
                    int $applicationId,
                    string $fromStatus,
                    string $toStatus,
                    ?DateTimeImmutable $submittedAt,
                    int $expectedStateVersion,
                    DateTimeImmutable $now,
                ): bool {
                    $ok = $this->inner->applyTransition(
                        $applicationId,
                        $fromStatus,
                        $toStatus,
                        $submittedAt,
                        $expectedStateVersion,
                        $now,
                    );
                    if ($ok && $toStatus === ApplicationStatus::ADMITTED) {
                        throw new RuntimeException('forced failure: after_application_transition');
                    }

                    return $ok;
                }
            };
        }

        if ($failAt === 'after_enrolment_insert') {
            $enrolments = new class ($enrolments) implements EnrolmentRepository {
                public function __construct(private EnrolmentRepository $inner)
                {
                }

                public function findById(int $enrolmentId): ?Enrolment
                {
                    return $this->inner->findById($enrolmentId);
                }

                public function findByApplicationId(int $applicationId): ?Enrolment
                {
                    return $this->inner->findByApplicationId($applicationId);
                }

                public function findByApplicationIdForUpdate(int $applicationId): ?Enrolment
                {
                    return $this->inner->findByApplicationIdForUpdate($applicationId);
                }

                public function findByPaymentId(int $paymentId): ?Enrolment
                {
                    return $this->inner->findByPaymentId($paymentId);
                }

                public function insertCreated(
                    string $publicReference,
                    int $applicationId,
                    int $userId,
                    int $courseId,
                    int $courseVersionId,
                    int $batchId,
                    int $paymentId,
                    string $lifecycleStatus,
                    ?string $academicStatus,
                    DateTimeImmutable $admittedAt,
                    ?DateTimeImmutable $activatedAt,
                    ?DateTimeImmutable $accessExpiresAt,
                    DateTimeImmutable $now,
                ): Enrolment {
                    $enrolment = $this->inner->insertCreated(
                        $publicReference,
                        $applicationId,
                        $userId,
                        $courseId,
                        $courseVersionId,
                        $batchId,
                        $paymentId,
                        $lifecycleStatus,
                        $academicStatus,
                        $admittedAt,
                        $activatedAt,
                        $accessExpiresAt,
                        $now,
                    );
                    throw new RuntimeException('forced failure: after_enrolment_insert');
                }

                public function countOccupiedSeatsForBatch(int $batchId): int
                {
                    return $this->inner->countOccupiedSeatsForBatch($batchId);
                }

                public function applyLifecycleTransition(
                    int $enrolmentId,
                    string $fromStatus,
                    string $toStatus,
                    int $expectedRowVersion,
                    DateTimeImmutable $now,
                    ?DateTimeImmutable $activatedAt = null,
                ): bool {
                    return $this->inner->applyLifecycleTransition(
                        $enrolmentId,
                        $fromStatus,
                        $toStatus,
                        $expectedRowVersion,
                        $now,
                        $activatedAt,
                    );
                }
            };
        }

        if ($failAt === 'after_outbox') {
            $outbox = new class ($outbox) implements OutboxWriter {
                public function __construct(private OutboxWriter $inner)
                {
                }

                public function enqueue(
                    string $eventType,
                    string $aggregateType,
                    string $aggregateId,
                    array $payload,
                    string $idempotencyKey,
                    ?string $correlationId = null,
                ): void {
                    $this->inner->enqueue(
                        $eventType,
                        $aggregateType,
                        $aggregateId,
                        $payload,
                        $idempotencyKey,
                        $correlationId,
                    );
                    throw new RuntimeException('forced failure: after_outbox');
                }
            };
        }

        if ($failAt === 'after_audit') {
            $writer = $container->get(AuditWriter::class);
            $failingWriter = new class ($writer) implements AuditWriter {
                public function __construct(private AuditWriter $inner)
                {
                }

                public function append(AuditRecord $record): void
                {
                    $this->inner->append($record);
                    throw new RuntimeException('forced failure: after_audit');
                }
            };
            $audit = new AuditService($failingWriter, $container->get(\Academy\Application\Audit\AuditRedactor::class));
        }

        return new SuccessfulPaymentAcceptanceService(
            $applications,
            $payments,
            $container->get(BatchRepository::class),
            $container->get(CourseVersionRepository::class),
            $enrolments,
            $container->get(EnrolmentFactory::class),
            $container->get(EnrolmentPublicReferenceGenerator::class),
            $container->get(EnrolmentStatusHistoryRepository::class),
            $container->get(ApplicationReviewAssignmentRepository::class),
            $container->get(PaymentStateMachine::class),
            $container->get(ApplicationStateMachine::class),
            $container->get(SuccessfulPaymentAcceptancePolicy::class),
            $container->get(BatchCapacityPolicy::class),
            $paymentHistory,
            $outbox,
            $audit,
        );
    }
}
