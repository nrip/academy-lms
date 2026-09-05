<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Assessments;

use Academy\Domain\Assessments\McqQuestionValidator;
use Academy\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class McqQuestionValidatorTest extends TestCase
{
    public function testRequiresAtLeastTwoOptionsAndOneCorrect(): void
    {
        McqQuestionValidator::assertValidOptions([
            ['option_text' => 'A', 'is_correct' => true],
            ['option_text' => 'B', 'is_correct' => false],
        ]);
        self::assertTrue(true);
    }

    public function testRejectsZeroCorrect(): void
    {
        $this->expectException(ValidationException::class);
        McqQuestionValidator::assertValidOptions([
            ['option_text' => 'A', 'is_correct' => false],
            ['option_text' => 'B', 'is_correct' => false],
        ]);
    }

    public function testRejectsMultipleCorrectForSingleMcq(): void
    {
        $this->expectException(ValidationException::class);
        McqQuestionValidator::assertValidOptions([
            ['option_text' => 'A', 'is_correct' => true],
            ['option_text' => 'B', 'is_correct' => true],
        ]);
    }

    public function testRejectsSingleOption(): void
    {
        $this->expectException(ValidationException::class);
        McqQuestionValidator::assertValidOptions([
            ['option_text' => 'A', 'is_correct' => true],
        ]);
    }
}
