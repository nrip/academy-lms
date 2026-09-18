<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Dashboard;

use Academy\Application\Dashboard\LearnerProgressNarrative;
use PHPUnit\Framework\TestCase;

final class LearnerProgressNarrativeTest extends TestCase
{
    public function testEmptyCurriculum(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(0, 0, 0, null, null, null, 0, true, 0);
        self::assertStringContainsString('Curriculum', $text);
    }

    public function testCompleteWithCertificate(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(5, 5, 100, null, null, null, 5, true, 1);
        self::assertStringContainsString('certificate', $text);
    }

    public function testContinueWithChapterIndex(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(
            1,
            4,
            25,
            'GLP-1 Therapies',
            'Foundations',
            3,
            5,
            true,
            0,
        );
        self::assertStringContainsString('Chapter 3 of 5', $text);
        self::assertStringContainsString('Continue with: GLP-1 Therapies', $text);
    }

    public function testOutlinePrefersChapterNarrative(): void
    {
        $text = LearnerProgressNarrative::forOutline(2, 8, 25, 2, 4, 'Insulin resistance', 0);
        self::assertStringContainsString('Chapter 2 of 4', $text);
        self::assertStringContainsString('Next: Insulin resistance', $text);
    }

    public function testInaccessibleBatch(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(0, 4, 0, null, null, 1, 4, false, 0);
        self::assertStringContainsString('batch begins', $text);
    }
}
