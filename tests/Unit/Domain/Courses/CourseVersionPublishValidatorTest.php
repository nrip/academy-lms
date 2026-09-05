<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentQuestionLink;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\ContentCompletionRule;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionPublishValidator;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Courses\Module;
use Academy\Domain\Courses\ModuleReleaseRule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CourseVersionPublishValidatorTest extends TestCase
{
    public function testReadyDraftWithModuleAndTextContentHasNoBlockers(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $version = $this->draftVersion($now);
        $module = new Module(1, 1, 1, 'M1', 'Desc', true, ModuleReleaseRule::IMMEDIATE, null, $now, $now);
        $item = new ContentItem(
            10,
            1,
            1,
            ContentItemType::TEXT_LESSON,
            'Lesson',
            'Body',
            null,
            true,
            ContentCompletionRule::MARK_COMPLETE,
            $now,
            $now,
        );

        $blockers = (new CourseVersionPublishValidator())->blockers($version, [$module], [$item], [], []);
        self::assertSame([], $blockers);
    }

    public function testPlaceholderFieldsAndMissingCurriculumBlock(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $version = new CourseVersion(
            versionId: 1,
            courseId: 1,
            versionNumber: 1,
            title: 'Title',
            description: 'Draft — update before publish.',
            learningObjectives: 'Draft — update before publish.',
            intendedAudience: 'Draft — update before publish.',
            syllabusSummary: 'Draft — update before publish.',
            admissionMode: 'A',
            deliveryType: 'online',
            durationText: 'To be confirmed',
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
            createdAt: $now,
            updatedAt: $now,
        );

        $blockers = (new CourseVersionPublishValidator())->blockers($version, [], [], [], []);
        self::assertNotEmpty($blockers);
        self::assertTrue(count($blockers) >= 4);
    }

    public function testIncompleteMcqAssessmentBlocks(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $version = $this->draftVersion($now);
        $module = new Module(1, 1, 1, 'M1', 'Desc', true, ModuleReleaseRule::IMMEDIATE, null, $now, $now);
        $item = new ContentItem(
            10,
            1,
            1,
            ContentItemType::MCQ_ASSESSMENT,
            'Final quiz',
            null,
            null,
            true,
            ContentCompletionRule::ASSESSMENT_PASSED,
            $now,
            $now,
        );
        $assessment = new Assessment(
            5,
            10,
            'Quiz',
            3,
            '60.00',
            null,
            2,
            null,
            false,
            false,
            $now,
            $now,
        );
        $links = [
            new AssessmentQuestionLink(1, 5, 100, 1, $now, $now),
        ];

        $blockers = (new CourseVersionPublishValidator())->blockers(
            $version,
            [$module],
            [$item],
            [10 => $assessment],
            [5 => $links],
        );
        self::assertNotEmpty($blockers);
        self::assertStringContainsString('Final quiz', implode(' ', $blockers));
    }

    private function draftVersion(DateTimeImmutable $now): CourseVersion
    {
        return new CourseVersion(
            versionId: 1,
            courseId: 1,
            versionNumber: 1,
            title: 'Ready title',
            description: 'Full description for clinicians.',
            learningObjectives: 'Learn metabolic assessment.',
            intendedAudience: 'Doctors and nurses.',
            syllabusSummary: 'Week-by-week syllabus.',
            admissionMode: 'A',
            deliveryType: 'online',
            durationText: '8 weeks',
            validityPeriodDays: 365,
            standardFee: '15000.00',
            gstRate: '18.00',
            currency: 'INR',
            certificateType: 'Certificate of Completion',
            faq: null,
            status: CourseVersionStatus::DRAFT,
            publishedAt: null,
            lockedAt: null,
            lockedReason: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
