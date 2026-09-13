<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Learning;

use Academy\Application\Learning\OutlineProgress;
use PHPUnit\Framework\TestCase;

final class OutlineProgressTest extends TestCase
{
    public function testUsesCompletedOverTotalWithoutCallingItCourseComplete(): void
    {
        self::assertSame(0, OutlineProgress::percent(0, 0));
        self::assertSame(0, OutlineProgress::percent(0, 4));
        self::assertSame(50, OutlineProgress::percent(1, 2));
        self::assertSame(33, OutlineProgress::percent(1, 3));
        self::assertSame(100, OutlineProgress::percent(3, 3));
    }
}
