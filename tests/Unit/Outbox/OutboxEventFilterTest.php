<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Outbox;

use Academy\Domain\Outbox\OutboxEventFilter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OutboxEventFilterTest extends TestCase
{
    public function testIncludeConstruction(): void
    {
        $filter = OutboxEventFilter::includeOnly(['identity.email_verification.send']);

        self::assertSame(OutboxEventFilter::MODE_INCLUDE, $filter->mode);
        self::assertSame(['identity.email_verification.send'], $filter->eventTypes);
    }

    public function testExcludeConstruction(): void
    {
        $filter = OutboxEventFilter::excluding([
            'identity.email_verification.send',
            'identity.password_reset.send',
        ]);

        self::assertSame(OutboxEventFilter::MODE_EXCLUDE, $filter->mode);
        self::assertCount(2, $filter->eventTypes);
    }

    public function testEmptyIncludeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OutboxEventFilter::includeOnly([]);
    }

    public function testEmptyExcludeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OutboxEventFilter::excluding([]);
    }
}
