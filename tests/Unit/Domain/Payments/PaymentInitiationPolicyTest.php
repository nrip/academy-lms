<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Payments;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentInitiationPolicy;
use Academy\Domain\Payments\PaymentStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PaymentInitiationPolicyTest extends TestCase
{
    public function testAllowsFirstAttemptWhenPaymentPending(): void
    {
        $policy = new PaymentInitiationPolicy();
        $policy->assertCanInitiate($this->application(), 10, []);
        self::assertSame(1, $policy->nextAttemptNumber([]));
    }

    public function testBlocksInFlightCreatedAttempt(): void
    {
        $policy = new PaymentInitiationPolicy();

        $this->expectException(ConflictException::class);
        $policy->assertCanInitiate(
            $this->application(),
            10,
            [$this->payment(PaymentStatus::CREATED)],
        );
    }

    public function testBlocksInFlightPendingAttempt(): void
    {
        $policy = new PaymentInitiationPolicy();

        $this->expectException(ConflictException::class);
        $policy->assertCanInitiate(
            $this->application(),
            10,
            [$this->payment(PaymentStatus::PENDING)],
        );
    }

    public function testAllowsRetryAfterFailed(): void
    {
        $policy = new PaymentInitiationPolicy();
        $existing = [$this->payment(PaymentStatus::FAILED, attemptNumber: 1)];
        $policy->assertCanInitiate($this->application(), 10, $existing);
        self::assertSame(2, $policy->nextAttemptNumber($existing));
    }

    public function testAllowsRetryAfterCancelled(): void
    {
        $policy = new PaymentInitiationPolicy();
        $policy->assertCanInitiate(
            $this->application(),
            10,
            [$this->payment(PaymentStatus::CANCELLED)],
        );
        self::assertTrue(true);
    }

    public function testAllowsRetryAfterExpired(): void
    {
        $policy = new PaymentInitiationPolicy();
        $policy->assertCanInitiate(
            $this->application(),
            10,
            [$this->payment(PaymentStatus::EXPIRED)],
        );
        self::assertTrue(true);
    }

    public function testBlocksSuccessfulAttempt(): void
    {
        $policy = new PaymentInitiationPolicy();

        $this->expectException(ConflictException::class);
        $policy->assertCanInitiate(
            $this->application(),
            10,
            [$this->payment(PaymentStatus::SUCCESSFUL)],
        );
    }

    public function testBlocksReconciliationPendingAttempt(): void
    {
        $policy = new PaymentInitiationPolicy();

        $this->expectException(ConflictException::class);
        $policy->assertCanInitiate(
            $this->application(),
            10,
            [$this->payment(PaymentStatus::RECONCILIATION_PENDING)],
        );
    }

    public function testWrongOwnerIsNotFound(): void
    {
        $policy = new PaymentInitiationPolicy();

        $this->expectException(NotFoundException::class);
        $policy->assertCanInitiate($this->application(), 99, []);
    }

    public function testWrongApplicationStatusIsConflict(): void
    {
        $policy = new PaymentInitiationPolicy();

        $this->expectException(ConflictException::class);
        $policy->assertCanInitiate(
            $this->application(ApplicationStatus::UNDER_REVIEW),
            10,
            [],
        );
    }

    private function application(string $status = ApplicationStatus::PAYMENT_PENDING): Application
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Application(
            applicationId: 7,
            applicationNumber: 'APP-7',
            userId: 10,
            courseVersionId: 1,
            batchId: 1,
            status: $status,
            stateVersion: 3,
            submittedAt: $now,
            declarationAcceptedVersion: '2026-07-22',
            declarationAcceptedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function payment(string $status, int $attemptNumber = 1): Payment
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Payment(
            paymentId: $attemptNumber,
            publicReference: 'PAY-7-0' . $attemptNumber . '-TEST',
            applicationId: 7,
            userId: 10,
            provider: 'razorpay',
            providerOrderId: null,
            providerPaymentId: null,
            baseFeeMinor: 1000000,
            gstMinor: 180000,
            amountMinor: 1180000,
            currency: 'INR',
            gstRatePercent: '18.00',
            courseVersionId: 1,
            batchId: 1,
            feeOverrideApplied: null,
            status: $status,
            failureCode: null,
            failureCategory: null,
            attemptNumber: $attemptNumber,
            idempotencyKey: 'pay:app:7:attempt:' . $attemptNumber,
            rowVersion: 1,
            successfulMarker: null,
            initiatedAt: $now,
            providerOrderBoundAt: null,
            authorizedAt: null,
            capturedAt: null,
            failedAt: null,
            expiredAt: null,
            reconciledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
