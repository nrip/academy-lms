<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Review;

use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Review\ReviewerScopeAssignment;
use Academy\Domain\Review\ReviewerScopeAssignmentRepository;
use Academy\Domain\Review\ReviewerScopePolicy;
use Academy\Domain\Review\ReviewerScopeType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReviewerScopePolicyTest extends TestCase
{
    public function testBatchScopeMatchesTargetBatch(): void
    {
        $at = new DateTimeImmutable('2026-07-15T12:00:00+00:00');
        $policy = new ReviewerScopePolicy(
            new FakeScopeAssignmentRepository([
                $this->assignment(
                    scopeType: ReviewerScopeType::BATCH,
                    batchId: 99,
                    effectiveFrom: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ]),
            new FakeCourseVersionRepository($this->courseVersion(publishedAt: new DateTimeImmutable('2026-06-01T00:00:00+00:00'))),
        );

        self::assertTrue($policy->isInScope(5, 1, 10, 99, $at));
        self::assertFalse($policy->isInScope(5, 1, 10, 100, $at));
    }

    public function testCourseVersionScopeMatchesExactVersion(): void
    {
        $at = new DateTimeImmutable('2026-07-15T12:00:00+00:00');
        $policy = new ReviewerScopePolicy(
            new FakeScopeAssignmentRepository([
                $this->assignment(
                    scopeType: ReviewerScopeType::COURSE_VERSION,
                    courseVersionId: 10,
                    effectiveFrom: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ]),
            new FakeCourseVersionRepository($this->courseVersion(publishedAt: new DateTimeImmutable('2026-06-01T00:00:00+00:00'))),
        );

        self::assertTrue($policy->isInScope(5, 1, 10, 99, $at));
        self::assertFalse($policy->isInScope(5, 1, 11, 99, $at));
    }

    public function testCourseScopeExcludesFutureVersionsWithoutFlag(): void
    {
        $at = new DateTimeImmutable('2026-07-15T12:00:00+00:00');
        $effectiveFrom = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
        $policy = new ReviewerScopePolicy(
            new FakeScopeAssignmentRepository([
                $this->assignment(
                    scopeType: ReviewerScopeType::COURSE,
                    courseId: 1,
                    includeFutureVersions: false,
                    effectiveFrom: $effectiveFrom,
                ),
            ]),
            new FakeCourseVersionRepository($this->courseVersion(
                publishedAt: new DateTimeImmutable('2026-07-01T00:00:00+00:00'),
            )),
        );

        self::assertFalse($policy->isInScope(5, 1, 10, 99, $at));
    }

    public function testCourseScopeIncludesFutureVersionsWhenFlagSet(): void
    {
        $at = new DateTimeImmutable('2026-07-15T12:00:00+00:00');
        $policy = new ReviewerScopePolicy(
            new FakeScopeAssignmentRepository([
                $this->assignment(
                    scopeType: ReviewerScopeType::COURSE,
                    courseId: 1,
                    includeFutureVersions: true,
                    effectiveFrom: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ]),
            new FakeCourseVersionRepository($this->courseVersion(
                publishedAt: new DateTimeImmutable('2026-12-01T00:00:00+00:00'),
            )),
        );

        self::assertTrue($policy->isInScope(5, 1, 10, 99, $at));
    }

    public function testRevokedAssignmentIsOutOfScope(): void
    {
        $at = new DateTimeImmutable('2026-07-15T12:00:00+00:00');
        $policy = new ReviewerScopePolicy(
            new FakeScopeAssignmentRepository([]),
            new FakeCourseVersionRepository($this->courseVersion(publishedAt: new DateTimeImmutable('2026-06-01T00:00:00+00:00'))),
        );

        self::assertFalse($policy->isInScope(5, 1, 10, 99, $at));
    }

    private function assignment(
        string $scopeType,
        ?int $courseId = null,
        ?int $courseVersionId = null,
        ?int $batchId = null,
        bool $includeFutureVersions = false,
        DateTimeImmutable $effectiveFrom = new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    ): ReviewerScopeAssignment {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new ReviewerScopeAssignment(
            scopeAssignmentId: 1,
            reviewerUserId: 5,
            scopeType: $scopeType,
            courseId: $courseId,
            courseVersionId: $courseVersionId,
            batchId: $batchId,
            includeFutureVersions: $includeFutureVersions,
            effectiveFrom: $effectiveFrom,
            effectiveTo: null,
            revokedAt: null,
            revokedReason: null,
            createdByUserId: 1,
            revokedByUserId: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function courseVersion(?DateTimeImmutable $publishedAt): CourseVersion
    {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new CourseVersion(
            versionId: 10,
            courseId: 1,
            versionNumber: 1,
            title: 'Test',
            description: 'Desc',
            learningObjectives: 'Obj',
            intendedAudience: 'Doctors',
            syllabusSummary: 'Syllabus',
            admissionMode: 'A',
            deliveryType: 'online',
            durationText: '4 weeks',
            validityPeriodDays: 365,
            standardFee: '10000.00',
            gstRate: '18.00',
            currency: 'INR',
            certificateType: 'Certificate',
            faq: null,
            status: CourseVersionStatus::PUBLISHED,
            publishedAt: $publishedAt,
            lockedAt: $now,
            lockedReason: 'published',
            createdAt: $now,
            updatedAt: $now,
        );
    }
}

/** @implements ReviewerScopeAssignmentRepository */
final class FakeScopeAssignmentRepository implements ReviewerScopeAssignmentRepository
{
    /** @param list<ReviewerScopeAssignment> $assignments */
    public function __construct(private readonly array $assignments)
    {
    }

    public function listActiveForReviewer(int $reviewerUserId, DateTimeImmutable $at): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (ReviewerScopeAssignment $assignment): bool => $assignment->reviewerUserId === $reviewerUserId
                && $assignment->isActiveAt($at),
        ));
    }
}

/** @implements CourseVersionRepository */
final class FakeCourseVersionRepository implements CourseVersionRepository
{
    public function __construct(private readonly ?CourseVersion $version)
    {
    }

    public function findById(int $versionId): ?CourseVersion
    {
        if ($this->version === null || $this->version->versionId !== $versionId) {
            return null;
        }

        return $this->version;
    }

    public function listByCourseId(int $courseId): array
    {
        if ($this->version === null || $this->version->courseId !== $courseId) {
            return [];
        }

        return [$this->version];
    }

    public function lock(int $versionId, string $lockedReason, DateTimeImmutable $lockedAt): void
    {
    }
}
