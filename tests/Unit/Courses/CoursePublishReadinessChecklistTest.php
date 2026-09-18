<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Courses;

use Academy\Application\Courses\CoursePublishReadinessChecklist;
use PHPUnit\Framework\TestCase;

final class CoursePublishReadinessChecklistTest extends TestCase
{
    public function testRequiredCompleteWhenAllRequiredDone(): void
    {
        $checklist = new CoursePublishReadinessChecklist([
            [
                'key' => 'info',
                'label' => 'Course information complete',
                'done' => true,
                'required' => true,
                'href' => null,
                'help' => '',
            ],
            [
                'key' => 'cover',
                'label' => 'Cover image added',
                'done' => false,
                'required' => false,
                'href' => null,
                'help' => '',
            ],
        ]);

        self::assertTrue($checklist->requiredComplete());
        self::assertSame(1, $checklist->doneCount());
        self::assertSame(2, $checklist->totalCount());
    }

    public function testRequiredIncompleteWhenRequiredMissing(): void
    {
        $checklist = new CoursePublishReadinessChecklist([
            [
                'key' => 'chapters',
                'label' => 'Chapters created',
                'done' => false,
                'required' => true,
                'href' => '/x',
                'help' => 'Create your first chapter.',
            ],
        ]);

        self::assertFalse($checklist->requiredComplete());
    }
}
