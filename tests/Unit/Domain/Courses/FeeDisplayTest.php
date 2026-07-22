<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\Batch;
use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Courses\FeeDisplay;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class FeeDisplayTest extends TestCase
{
    public function testGstAmountComputesExactPercentage(): void
    {
        self::assertSame('1800.00', FeeDisplay::gstAmount('10000.00', '18.00'));
    }

    public function testInclusiveAmountAddsGstToBaseFee(): void
    {
        self::assertSame('11800.00', FeeDisplay::inclusiveAmount('10000.00', '18.00'));
    }

    public function testGstAmountRoundsHalfUpToTwoDecimals(): void
    {
        // 999.99 * 12.36% = 123.598764 -> rounds to 123.60
        self::assertSame('123.60', FeeDisplay::gstAmount('999.99', '12.36'));
    }

    public function testZeroGstRateYieldsZeroGst(): void
    {
        self::assertSame('0.00', FeeDisplay::gstAmount('10000.00', '0.00'));
        self::assertSame('10000.00', FeeDisplay::inclusiveAmount('10000.00', '0.00'));
    }

    public function testFormattedGroupsThousands(): void
    {
        self::assertSame('INR 11,800.00', FeeDisplay::formatted('11800.00', 'INR'));
        self::assertSame('INR 180.00', FeeDisplay::formatted('180.00', 'INR'));
        self::assertSame('INR 1,234,567.89', FeeDisplay::formatted('1234567.89', 'INR'));
    }

    public function testEffectiveBaseFeeUsesOverrideWhenPresent(): void
    {
        $version = $this->version('10000.00');
        $batch = $this->batch('12000.00');

        self::assertSame('12000.00', FeeDisplay::effectiveBaseFee($batch, $version));
    }

    public function testEffectiveBaseFeeFallsBackToStandardFee(): void
    {
        $version = $this->version('10000.00');
        $batch = $this->batch(null);

        self::assertSame('10000.00', FeeDisplay::effectiveBaseFee($batch, $version));
    }

    private function version(string $standardFee): CourseVersion
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
            gstRate: '18.00',
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

    private function batch(?string $feeOverride): Batch
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Batch(
            batchId: 1,
            courseVersionId: 1,
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
