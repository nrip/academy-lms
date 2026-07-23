<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Payments;

use Academy\Domain\Courses\Batch;
use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentAmountSnapshotTest extends TestCase
{
    public function testDecimalToMinorUsesIntegerArithmetic(): void
    {
        self::assertSame(1000000, PaymentAmountSnapshot::decimalToMinor('10000.00'));
        self::assertSame(1000000, PaymentAmountSnapshot::decimalToMinor('10000'));
        self::assertSame(9999, PaymentAmountSnapshot::decimalToMinor('99.99'));
        self::assertSame(-150, PaymentAmountSnapshot::decimalToMinor('-1.50'));
    }

    public function testMinorToDecimalRoundTrips(): void
    {
        self::assertSame('10000.00', PaymentAmountSnapshot::minorToDecimal(1000000));
        self::assertSame('0.01', PaymentAmountSnapshot::minorToDecimal(1));
        self::assertSame('-1.50', PaymentAmountSnapshot::minorToDecimal(-150));
    }

    public function testRejectsInvalidDecimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaymentAmountSnapshot::decimalToMinor('10.123');
    }

    public function testRejectsScientificNotation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaymentAmountSnapshot::decimalToMinor('1e2');
    }

    public function testFromBatchAndVersionUsesStandardFeeAndGst(): void
    {
        $snapshot = PaymentAmountSnapshot::fromBatchAndVersion(
            $this->batch(null),
            $this->version('10000.00', '18.00'),
        );

        self::assertSame(1000000, $snapshot->baseFeeMinor);
        self::assertSame(180000, $snapshot->gstMinor);
        self::assertSame(1180000, $snapshot->totalPayableMinor);
        self::assertSame('INR', $snapshot->currency);
        self::assertSame('18.00', $snapshot->gstRatePercent);
        self::assertNull($snapshot->feeOverrideApplied);
    }

    public function testFromBatchAndVersionAppliesFeeOverride(): void
    {
        $snapshot = PaymentAmountSnapshot::fromBatchAndVersion(
            $this->batch('12000.00'),
            $this->version('10000.00', '18.00'),
        );

        self::assertSame(1200000, $snapshot->baseFeeMinor);
        self::assertSame(216000, $snapshot->gstMinor);
        self::assertSame(1416000, $snapshot->totalPayableMinor);
        self::assertSame('12000.00', $snapshot->feeOverrideApplied);
    }

    public function testConstructorRejectsMismatchedTotal(): void
    {
        $this->expectException(DomainRuleException::class);
        new PaymentAmountSnapshot(
            baseFeeMinor: 100,
            gstMinor: 18,
            totalPayableMinor: 200,
            currency: 'INR',
            gstRatePercent: '18.00',
            courseVersionId: 1,
            batchId: 1,
            feeOverrideApplied: null,
        );
    }

    public function testConstructorRejectsZeroTotal(): void
    {
        $this->expectException(DomainRuleException::class);
        new PaymentAmountSnapshot(
            baseFeeMinor: 0,
            gstMinor: 0,
            totalPayableMinor: 0,
            currency: 'INR',
            gstRatePercent: '0.00',
            courseVersionId: 1,
            batchId: 1,
            feeOverrideApplied: null,
        );
    }

    public function testBatchVersionMismatchIsRejected(): void
    {
        $this->expectException(DomainRuleException::class);
        PaymentAmountSnapshot::fromBatchAndVersion(
            $this->batch(null, courseVersionId: 99),
            $this->version('10000.00', '18.00'),
        );
    }

    private function version(string $standardFee, string $gstRate): CourseVersion
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new CourseVersion(
            versionId: 1,
            courseId: 1,
            versionNumber: 1,
            title: 'Title',
            description: 'Description',
            learningObjectives: 'Objectives',
            intendedAudience: 'Audience',
            syllabusSummary: 'Syllabus',
            admissionMode: 'A',
            deliveryType: 'online',
            durationText: '4 weeks',
            validityPeriodDays: 365,
            standardFee: $standardFee,
            gstRate: $gstRate,
            currency: 'INR',
            certificateType: 'Certificate of Completion',
            faq: null,
            status: CourseVersionStatus::PUBLISHED,
            publishedAt: $now,
            lockedAt: $now,
            lockedReason: 'published',
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function batch(?string $feeOverride, int $courseVersionId = 1): Batch
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Batch(
            batchId: 1,
            courseVersionId: $courseVersionId,
            batchCode: 'BATCH-1',
            name: 'Batch 1',
            startsAt: $now,
            endsAt: $now,
            applicationsOpenAt: $now,
            applicationsCloseAt: $now,
            minCapacity: 5,
            maxCapacity: 30,
            deliveryMode: 'online',
            venueOrOnlineDetails: 'Online',
            timezone: 'Asia/Kolkata',
            feeOverride: $feeOverride,
            currency: 'INR',
            status: BatchStatus::OPEN_FOR_APPLICATIONS,
            accessExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
