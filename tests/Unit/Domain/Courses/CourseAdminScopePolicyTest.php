<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\CourseAdminScopeAssignment;
use Academy\Domain\Courses\CourseAdminScopeAssignmentRepository;
use Academy\Domain\Courses\CourseAdminScopePolicy;
use Academy\Domain\Courses\CourseAdminScopeType;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Courses\CourseVersionStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CourseAdminScopePolicyTest extends TestCase
{
    public function testCourseScopeWithoutFutureVersionsExcludesLaterDraft(): void
    {
        $assignedAt = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'));
        $later = new DateTimeImmutable('2026-02-01 10:00:00', new DateTimeZone('UTC'));

        $policy = new CourseAdminScopePolicy(
            $this->assignments([
                $this->courseAssignment(1, 10, false, $assignedAt),
            ]),
            $this->versions([
                100 => $this->version(100, 10, 1, $assignedAt),
                101 => $this->version(101, 10, 2, $later),
            ]),
        );

        $at = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
        self::assertTrue($policy->isVersionInScope(1, 10, 100, $at));
        self::assertFalse($policy->isVersionInScope(1, 10, 101, $at));
    }

    public function testCourseScopeWithFutureVersionsIncludesLaterDraft(): void
    {
        $assignedAt = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'));
        $later = new DateTimeImmutable('2026-02-01 10:00:00', new DateTimeZone('UTC'));

        $policy = new CourseAdminScopePolicy(
            $this->assignments([
                $this->courseAssignment(1, 10, true, $assignedAt),
            ]),
            $this->versions([
                101 => $this->version(101, 10, 2, $later),
            ]),
        );

        $at = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
        self::assertTrue($policy->isVersionInScope(1, 10, 101, $at));
        self::assertTrue($policy->isCourseInScope(1, 10, $at));
    }

    public function testRevokedAssignmentDoesNotMatch(): void
    {
        $assignedAt = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'));
        $policy = new CourseAdminScopePolicy(
            $this->assignments([]),
            $this->versions([
                100 => $this->version(100, 10, 1, $assignedAt),
            ]),
        );

        $at = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
        self::assertFalse($policy->isCourseInScope(1, 10, $at));
    }

    /**
     * @param list<CourseAdminScopeAssignment> $list
     */
    private function assignments(array $list): CourseAdminScopeAssignmentRepository
    {
        return new class ($list) implements CourseAdminScopeAssignmentRepository {
            /** @param list<CourseAdminScopeAssignment> $list */
            public function __construct(private readonly array $list)
            {
            }

            public function listActiveForAdmin(int $adminUserId, DateTimeImmutable $at): array
            {
                return array_values(array_filter(
                    $this->list,
                    static fn (CourseAdminScopeAssignment $a): bool => $a->adminUserId === $adminUserId && $a->isActiveAt($at),
                ));
            }

            public function insertCourseScope(
                int $adminUserId,
                int $courseId,
                bool $includeFutureVersions,
                DateTimeImmutable $effectiveFrom,
                int $createdByUserId,
            ): int {
                return 0;
            }

            public function insertCourseVersionScope(
                int $adminUserId,
                int $courseVersionId,
                DateTimeImmutable $effectiveFrom,
                int $createdByUserId,
            ): int {
                return 0;
            }

            public function findActiveCourseScope(int $adminUserId, int $courseId, DateTimeImmutable $at): ?CourseAdminScopeAssignment
            {
                return null;
            }

            public function revoke(int $scopeAssignmentId, int $revokedByUserId, string $reason, DateTimeImmutable $revokedAt): void
            {
            }
        };
    }

    /**
     * @param array<int, CourseVersion> $byId
     */
    private function versions(array $byId): CourseVersionRepository
    {
        return new class ($byId) implements CourseVersionRepository {
            /** @param array<int, CourseVersion> $byId */
            public function __construct(private readonly array $byId)
            {
            }

            public function findById(int $versionId): ?CourseVersion
            {
                return $this->byId[$versionId] ?? null;
            }

            public function listByCourseId(int $courseId): array
            {
                return [];
            }

            public function insertDraft(array $fields): int
            {
                return 0;
            }

            public function updateDraftOverview(int $versionId, array $fields): bool
            {
                return false;
            }

            public function lock(int $versionId, string $lockedReason, DateTimeImmutable $lockedAt): void
            {
            }
        };
    }

    private function courseAssignment(
        int $adminUserId,
        int $courseId,
        bool $includeFuture,
        DateTimeImmutable $from,
    ): CourseAdminScopeAssignment {
        return new CourseAdminScopeAssignment(
            scopeAssignmentId: 1,
            adminUserId: $adminUserId,
            scopeType: CourseAdminScopeType::COURSE,
            courseId: $courseId,
            courseVersionId: null,
            includeFutureVersions: $includeFuture,
            effectiveFrom: $from,
            effectiveTo: null,
            revokedAt: null,
            revokedReason: null,
            createdByUserId: $adminUserId,
            revokedByUserId: null,
            createdAt: $from,
            updatedAt: $from,
        );
    }

    private function version(int $id, int $courseId, int $number, DateTimeImmutable $createdAt): CourseVersion
    {
        return new CourseVersion(
            versionId: $id,
            courseId: $courseId,
            versionNumber: $number,
            title: 'T',
            description: 'D',
            learningObjectives: 'O',
            intendedAudience: 'A',
            syllabusSummary: 'S',
            admissionMode: 'A',
            deliveryType: 'online',
            durationText: '1 week',
            validityPeriodDays: null,
            standardFee: '0.00',
            gstRate: '18.00',
            currency: 'INR',
            certificateType: 'Certificate of Completion',
            faq: null,
            status: CourseVersionStatus::DRAFT,
            publishedAt: null,
            lockedAt: null,
            lockedReason: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );
    }
}
