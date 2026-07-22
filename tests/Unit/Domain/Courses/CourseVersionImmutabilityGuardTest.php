<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionImmutabilityGuard;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Exception\ConflictException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CourseVersionImmutabilityGuardTest extends TestCase
{
    public function testUnlockedVersionIsMutable(): void
    {
        $guard = new CourseVersionImmutabilityGuard();

        $guard->assertMutable($this->version(locked: false));

        $this->addToAssertionCount(1);
    }

    public function testLockedVersionThrowsConflictException(): void
    {
        $guard = new CourseVersionImmutabilityGuard();

        $this->expectException(ConflictException::class);
        $guard->assertMutable($this->version(locked: true));
    }

    private function version(bool $locked): CourseVersion
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
            standardFee: '10000.00',
            gstRate: '18.00',
            currency: 'INR',
            certificateType: 'Certificate of Completion',
            faq: null,
            status: $locked ? CourseVersionStatus::PUBLISHED : CourseVersionStatus::DRAFT,
            publishedAt: $locked ? $now : null,
            lockedAt: $locked ? $now : null,
            lockedReason: $locked ? 'published' : null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
