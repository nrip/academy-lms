<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Review;

use Academy\Domain\Review\ReviewerQueueFilter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewerQueueFilterTest extends TestCase
{
    #[DataProvider('validFilters')]
    public function testValidFiltersPass(string $filter): void
    {
        ReviewerQueueFilter::assertValid($filter);
        self::assertSame($filter, ReviewerQueueFilter::normalize($filter));
    }

    public function testNullOrEmptyNormalizesToDefault(): void
    {
        self::assertSame(ReviewerQueueFilter::DEFAULT, ReviewerQueueFilter::normalize(null));
        self::assertSame(ReviewerQueueFilter::DEFAULT, ReviewerQueueFilter::normalize(''));
        self::assertSame(ReviewerQueueFilter::DEFAULT, ReviewerQueueFilter::normalize('   '));
    }

    public function testInvalidFilterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewerQueueFilter::assertValid('assigned_to_someone_else');
    }

    public function testNormalizeInvalidFilterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewerQueueFilter::normalize('bogus');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function validFilters(): array
    {
        return array_map(static fn (string $filter): array => [$filter], ReviewerQueueFilter::ALL);
    }
}
