<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Dashboard;

use Academy\Application\Dashboard\LearnerProgressNarrative;
use PHPUnit\Framework\TestCase;

final class LearnerProgressNarrativeTest extends TestCase
{
    public function testEmptyCurriculum(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(0, 0, 0, null, null, true, 0);
        self::assertStringContainsString('Curriculum', $text);
    }

    public function testCompleteWithCertificate(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(5, 5, 100, null, null, true, 1);
        self::assertStringContainsString('certificate', $text);
    }

    public function testContinueWithChapter(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(
            1,
            4,
            25,
            'Insulin resistance',
            'Foundations',
            true,
            0,
        );
        self::assertStringContainsString('Continue with Insulin resistance', $text);
        self::assertStringContainsString('Chapter: Foundations', $text);
    }

    public function testInaccessibleBatch(): void
    {
        $text = LearnerProgressNarrative::forStudyCard(0, 4, 0, null, null, false, 0);
        self::assertStringContainsString('batch begins', $text);
    }
}
