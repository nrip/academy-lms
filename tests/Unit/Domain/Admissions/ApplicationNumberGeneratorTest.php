<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Admissions;

use Academy\Domain\Admissions\ApplicationNumberGenerator;
use PHPUnit\Framework\TestCase;

final class ApplicationNumberGeneratorTest extends TestCase
{
    public function testGeneratesExpectedFormat(): void
    {
        $number = (new ApplicationNumberGenerator())->generate();

        self::assertMatchesRegularExpression('/^APP-\d{6}-[0-9A-F]{10}$/', $number);
    }

    public function testGeneratesUniqueValuesAcrossManyCalls(): void
    {
        $generator = new ApplicationNumberGenerator();

        $numbers = [];
        for ($i = 0; $i < 200; $i++) {
            $numbers[] = $generator->generate();
        }

        self::assertCount(200, array_unique($numbers));
    }

    public function testEmbedsTodaysDateInUtc(): void
    {
        $number = (new ApplicationNumberGenerator())->generate();
        $today = gmdate('ymd');

        self::assertStringContainsString('APP-' . $today . '-', $number);
    }
}
