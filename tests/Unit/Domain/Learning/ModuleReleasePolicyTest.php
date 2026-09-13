<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Learning;

use Academy\Domain\Courses\ContentCompletionRule;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\Module;
use Academy\Domain\Courses\ModuleReleaseRule;
use Academy\Domain\Learning\ContentProgress;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Domain\Learning\ModuleReleasePolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ModuleReleasePolicyTest extends TestCase
{
    public function testImmediateModuleItemsRequirePriorMandatoryCompletion(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $module = new Module(1, 10, 1, 'M1', 'd', true, ModuleReleaseRule::IMMEDIATE, null, $now, $now);
        $item1 = new ContentItem(1, 1, 1, ContentItemType::TEXT_LESSON, 'A', 'body', null, null, null, null, true, ContentCompletionRule::MARK_COMPLETE, $now, $now);
        $item2 = new ContentItem(2, 1, 2, ContentItemType::TEXT_LESSON, 'B', 'body', null, null, null, null, true, ContentCompletionRule::MARK_COMPLETE, $now, $now);
        $policy = new ModuleReleasePolicy();

        self::assertTrue($policy->isContentAccessible($item1, [$module], [$item1, $item2], []));
        self::assertFalse($policy->isContentAccessible($item2, [$module], [$item1, $item2], []));

        $progress = new ContentProgress(
            1,
            5,
            1,
            ContentProgressCompletionStatus::COMPLETED,
            null,
            null,
            $now,
            $now,
            $now,
            false,
            'learner',
            1,
            $now,
            $now,
        );
        self::assertTrue($policy->isContentAccessible($item2, [$module], [$item1, $item2], [1 => $progress]));
    }

    public function testSequentialModuleLockedUntilPriorModuleComplete(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $m1 = new Module(1, 10, 1, 'M1', 'd', true, ModuleReleaseRule::IMMEDIATE, null, $now, $now);
        $m2 = new Module(2, 10, 2, 'M2', 'd', true, ModuleReleaseRule::SEQUENTIAL, 1, $now, $now);
        $i1 = new ContentItem(1, 1, 1, ContentItemType::TEXT_LESSON, 'A', 'body', null, null, null, null, true, ContentCompletionRule::MARK_COMPLETE, $now, $now);
        $i2 = new ContentItem(2, 2, 1, ContentItemType::TEXT_LESSON, 'B', 'body', null, null, null, null, true, ContentCompletionRule::MARK_COMPLETE, $now, $now);
        $policy = new ModuleReleasePolicy();

        self::assertFalse($policy->isModuleUnlocked($m2, [$m1, $m2], [$i1, $i2], []));
        $progress = new ContentProgress(
            1,
            5,
            1,
            ContentProgressCompletionStatus::COMPLETED,
            null,
            null,
            $now,
            $now,
            $now,
            false,
            'learner',
            1,
            $now,
            $now,
        );
        self::assertTrue($policy->isModuleUnlocked($m2, [$m1, $m2], [$i1, $i2], [1 => $progress]));
        self::assertTrue($policy->isContentAccessible($i2, [$m1, $m2], [$i1, $i2], [1 => $progress]));
    }
}
