<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\ModuleReleaseRule;
use Academy\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class CurriculumValueObjectsTest extends TestCase
{
    public function testContentTypeRejectsMcqInBuilder(): void
    {
        $this->expectException(ValidationException::class);
        ContentItemType::assertCreatable(ContentItemType::MCQ_ASSESSMENT);
    }

    public function testReleaseRuleRejectsUnknown(): void
    {
        $this->expectException(ValidationException::class);
        ModuleReleaseRule::assertValid('date_based');
    }

    public function testTextLessonIsCreatable(): void
    {
        self::assertSame(
            ContentItemType::TEXT_LESSON,
            ContentItemType::assertCreatable(ContentItemType::TEXT_LESSON),
        );
    }
}
