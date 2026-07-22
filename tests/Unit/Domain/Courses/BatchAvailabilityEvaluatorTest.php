<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\Batch;
use Academy\Domain\Courses\BatchAvailability;
use Academy\Domain\Courses\BatchAvailabilityEvaluator;
use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseStatus;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class BatchAvailabilityEvaluatorTest extends TestCase
{
    private BatchAvailabilityEvaluator $evaluator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->evaluator = new BatchAvailabilityEvaluator();
        $this->now = new DateTimeImmutable('2027-01-15 12:00:00', new DateTimeZone('UTC'));
    }

    public function testSelectableWhenAllConditionsHold(): void
    {
        $result = $this->evaluator->evaluate($this->course(), $this->version(), $this->batch(), $this->now);

        self::assertTrue($result->selectable);
        self::assertSame(BatchAvailability::REASON_SELECTABLE, $result->reasonCode);
    }

    public function testCourseNotActiveIsNotSelectable(): void
    {
        $result = $this->evaluator->evaluate(
            $this->course(status: CourseStatus::RETIRED),
            $this->version(),
            $this->batch(),
            $this->now,
        );

        self::assertFalse($result->selectable);
        self::assertSame(BatchAvailability::REASON_COURSE_NOT_ACTIVE, $result->reasonCode);
    }

    public function testUnpublishedVersionIsNotSelectable(): void
    {
        $result = $this->evaluator->evaluate(
            $this->course(),
            $this->version(status: CourseVersionStatus::DRAFT, locked: true),
            $this->batch(),
            $this->now,
        );

        self::assertFalse($result->selectable);
        self::assertSame(BatchAvailability::REASON_VERSION_NOT_PUBLISHED, $result->reasonCode);
    }

    public function testPublishedButUnlockedVersionIsNotSelectable(): void
    {
        $result = $this->evaluator->evaluate(
            $this->course(),
            $this->version(status: CourseVersionStatus::PUBLISHED, locked: false),
            $this->batch(),
            $this->now,
        );

        self::assertFalse($result->selectable);
        self::assertSame(BatchAvailability::REASON_VERSION_NOT_PUBLISHED, $result->reasonCode);
    }

    public function testBatchNotOpenIsNotSelectable(): void
    {
        $result = $this->evaluator->evaluate(
            $this->course(),
            $this->version(),
            $this->batch(status: BatchStatus::PLANNED),
            $this->now,
        );

        self::assertFalse($result->selectable);
        self::assertSame(BatchAvailability::REASON_BATCH_NOT_OPEN, $result->reasonCode);
    }

    public function testFullBatchIsNotSelectable(): void
    {
        $result = $this->evaluator->evaluate(
            $this->course(),
            $this->version(),
            $this->batch(status: BatchStatus::FULL),
            $this->now,
        );

        self::assertFalse($result->selectable);
        self::assertSame(BatchAvailability::REASON_BATCH_NOT_OPEN, $result->reasonCode);
    }

    public function testBeforeApplicationWindowIsNotSelectable(): void
    {
        $result = $this->evaluator->evaluate(
            $this->course(),
            $this->version(),
            $this->batch(
                applicationsOpenAt: $this->now->modify('+1 day'),
                applicationsCloseAt: $this->now->modify('+10 days'),
            ),
            $this->now,
        );

        self::assertFalse($result->selectable);
        self::assertSame(BatchAvailability::REASON_BEFORE_WINDOW, $result->reasonCode);
    }

    public function testAfterApplicationWindowIsNotSelectable(): void
    {
        $result = $this->evaluator->evaluate(
            $this->course(),
            $this->version(),
            $this->batch(
                applicationsOpenAt: $this->now->modify('-20 days'),
                applicationsCloseAt: $this->now->modify('-1 day'),
            ),
            $this->now,
        );

        self::assertFalse($result->selectable);
        self::assertSame(BatchAvailability::REASON_AFTER_WINDOW, $result->reasonCode);
    }

    public function testWindowBoundariesAreInclusive(): void
    {
        $openResult = $this->evaluator->evaluate(
            $this->course(),
            $this->version(),
            $this->batch(applicationsOpenAt: $this->now, applicationsCloseAt: $this->now->modify('+10 days')),
            $this->now,
        );
        self::assertTrue($openResult->selectable);

        $closeResult = $this->evaluator->evaluate(
            $this->course(),
            $this->version(),
            $this->batch(applicationsOpenAt: $this->now->modify('-10 days'), applicationsCloseAt: $this->now),
            $this->now,
        );
        self::assertTrue($closeResult->selectable);
    }

    public function testDefaultsToCurrentUtcTimeWhenNowNotProvided(): void
    {
        $openBatch = $this->batch(
            applicationsOpenAt: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day'),
            applicationsCloseAt: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 day'),
        );

        $result = $this->evaluator->evaluate($this->course(), $this->version(), $openBatch);

        self::assertTrue($result->selectable);
    }

    private function course(string $status = CourseStatus::ACTIVE): Course
    {
        return new Course(
            courseId: 1,
            courseCode: 'TEST-1',
            slug: 'test-1',
            masterTitle: 'Test Course',
            status: $status,
            currentPublishedVersionId: 10,
            createdAt: $this->now,
            updatedAt: $this->now,
        );
    }

    private function version(
        string $status = CourseVersionStatus::PUBLISHED,
        bool $locked = true,
    ): CourseVersion {
        return new CourseVersion(
            versionId: 10,
            courseId: 1,
            versionNumber: 1,
            title: 'Test Course V1',
            description: 'Description',
            learningObjectives: 'Objectives',
            intendedAudience: 'Audience',
            syllabusSummary: 'Syllabus',
            admissionMode: 'A',
            deliveryType: 'online',
            durationText: '4 weeks',
            validityPeriodDays: 365,
            standardFee: '10000.00',
            gstRate: '18.00',
            currency: 'INR',
            certificateType: 'Certificate of Completion',
            faq: null,
            status: $status,
            publishedAt: $status === CourseVersionStatus::PUBLISHED ? $this->now : null,
            lockedAt: $locked ? $this->now : null,
            lockedReason: $locked ? 'published' : null,
            createdAt: $this->now,
            updatedAt: $this->now,
        );
    }

    private function batch(
        string $status = BatchStatus::OPEN_FOR_APPLICATIONS,
        ?DateTimeImmutable $applicationsOpenAt = null,
        ?DateTimeImmutable $applicationsCloseAt = null,
    ): Batch {
        return new Batch(
            batchId: 100,
            courseVersionId: 10,
            batchCode: 'BATCH-1',
            name: 'Batch 1',
            startsAt: $this->now->modify('+30 days'),
            endsAt: $this->now->modify('+60 days'),
            applicationsOpenAt: $applicationsOpenAt ?? $this->now->modify('-5 days'),
            applicationsCloseAt: $applicationsCloseAt ?? $this->now->modify('+10 days'),
            minCapacity: 5,
            maxCapacity: 30,
            deliveryMode: 'online',
            venueOrOnlineDetails: 'Online',
            timezone: 'Asia/Kolkata',
            feeOverride: null,
            currency: 'INR',
            status: $status,
            accessExpiresAt: null,
            createdAt: $this->now,
            updatedAt: $this->now,
        );
    }
}
