<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\EmailNormalizer;
use PHPUnit\Framework\TestCase;

final class EmailNormalizerTest extends TestCase
{
    public function testLowercasesAndTrims(): void
    {
        self::assertSame('learner@example.com', EmailNormalizer::normalize('  Learner@Example.COM  '));
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(ValidationException::class);
        EmailNormalizer::normalize('   ');
    }

    public function testRejectsMalformedEmail(): void
    {
        $this->expectException(ValidationException::class);
        EmailNormalizer::normalize('not-an-email');
    }

    public function testRejectsOverlongEmail(): void
    {
        $local = str_repeat('a', 250);
        $this->expectException(ValidationException::class);
        EmailNormalizer::normalize($local . '@example.com');
    }

    public function testAcceptsLongButValidEmailNearMaximumLength(): void
    {
        // filter_var(FILTER_VALIDATE_EMAIL) enforces RFC 5321's 64-char local-part limit,
        // so the longest *valid* email is built from a 64-char local part plus a long domain
        // (rather than a naive 255-char local part, which filter_var itself would reject).
        $local = str_repeat('a', 64);
        $domain = str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.' . str_repeat('d', 60) . '.com';
        $email = $local . '@' . $domain;
        self::assertLessThanOrEqual(255, strlen($email));
        self::assertSame($email, EmailNormalizer::normalize($email));
    }

    public function testValidationExceptionCarriesEmailField(): void
    {
        try {
            EmailNormalizer::normalize('bad');
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->fields());
        }
    }
}
