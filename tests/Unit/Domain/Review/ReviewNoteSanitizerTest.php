<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Review;

use Academy\Domain\Review\ReviewNoteSanitizer;
use PHPUnit\Framework\TestCase;

final class ReviewNoteSanitizerTest extends TestCase
{
    public function testStripsScriptTagsFromLearnerMessage(): void
    {
        $sanitized = ReviewNoteSanitizer::sanitizeLearnerVisible('<script>alert(1)</script>Please re-upload');
        self::assertSame('alert(1)Please re-upload', $sanitized);
        self::assertStringNotContainsString('<script>', (string) $sanitized);
    }

    public function testTrimsAndNullifiesBlankLearnerMessage(): void
    {
        self::assertNull(ReviewNoteSanitizer::sanitizeLearnerVisible('   '));
        self::assertNull(ReviewNoteSanitizer::sanitizeLearnerVisible(null));
    }

    public function testTruncatesLongLearnerMessage(): void
    {
        $long = str_repeat('x', ReviewNoteSanitizer::LEARNER_MAX_LENGTH + 50);
        $sanitized = ReviewNoteSanitizer::sanitizeLearnerVisible($long);
        self::assertNotNull($sanitized);
        self::assertSame(ReviewNoteSanitizer::LEARNER_MAX_LENGTH, mb_strlen((string) $sanitized));
    }

    public function testInternalNoteUsesSeparateLimit(): void
    {
        $long = str_repeat('n', ReviewNoteSanitizer::INTERNAL_MAX_LENGTH + 10);
        $sanitized = ReviewNoteSanitizer::sanitizeInternal($long);
        self::assertNotNull($sanitized);
        self::assertSame(ReviewNoteSanitizer::INTERNAL_MAX_LENGTH, mb_strlen((string) $sanitized));
    }
}
