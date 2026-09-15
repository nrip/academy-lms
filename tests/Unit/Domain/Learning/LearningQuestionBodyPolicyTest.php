<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Learning;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Learning\LearningQuestionBodyPolicy;
use PHPUnit\Framework\TestCase;

final class LearningQuestionBodyPolicyTest extends TestCase
{
    public function testNormalizeTrimsAndAcceptsPlainText(): void
    {
        self::assertSame("Hello faculty\nline two", LearningQuestionBodyPolicy::normalize("  Hello faculty\r\nline two  "));
    }

    public function testNormalizeRejectsEmpty(): void
    {
        $this->expectException(ValidationException::class);
        LearningQuestionBodyPolicy::normalize('   ');
    }

    public function testNormalizeRejectsOverLimit(): void
    {
        $this->expectException(ValidationException::class);
        LearningQuestionBodyPolicy::normalize(str_repeat('a', 2001));
    }

    public function testNormalizeResponseUsesResponseWording(): void
    {
        try {
            LearningQuestionBodyPolicy::normalizeResponse('');
            self::fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('response', strtolower($exception->getMessage()));
        }
    }
}
