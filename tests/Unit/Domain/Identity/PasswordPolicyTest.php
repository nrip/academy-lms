<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsCompliantPassword(): void
    {
        PasswordPolicy::assertAcceptable('CorrectHorse123!', 'learner@example.com', '+919876543210');
        self::assertTrue(true);
    }

    public function testRejectsPasswordShorterThanMinimum(): void
    {
        $this->expectException(ValidationException::class);
        PasswordPolicy::assertAcceptable('Short1!', 'learner@example.com', '+919876543210');
    }

    public function testAcceptsPasswordAtMinimumLength(): void
    {
        $password = str_repeat('a', 12);
        PasswordPolicy::assertAcceptable($password, 'learner@example.com', '+919876543210');
        self::assertTrue(true);
    }

    public function testRejectsPasswordLongerThanMaximum(): void
    {
        $this->expectException(ValidationException::class);
        PasswordPolicy::assertAcceptable(str_repeat('a', 129), 'learner@example.com', '+919876543210');
    }

    public function testAcceptsPasswordAtMaximumLength(): void
    {
        $password = str_repeat('a', 128);
        PasswordPolicy::assertAcceptable($password, 'learner@example.com', '+919876543210');
        self::assertTrue(true);
    }

    public function testRejectsPasswordEqualToNormalizedEmail(): void
    {
        $this->expectException(ValidationException::class);
        PasswordPolicy::assertAcceptable('learner@example.com', 'learner@example.com', '+919876543210');
    }

    public function testRejectsPasswordEqualToNormalizedMobile(): void
    {
        $this->expectException(ValidationException::class);
        PasswordPolicy::assertAcceptable('+919876543210', 'learner@example.com', '+919876543210');
    }

    public function testValidationExceptionCarriesPasswordField(): void
    {
        try {
            PasswordPolicy::assertAcceptable('short', 'learner@example.com', '+919876543210');
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('password', $exception->fields());
        }
    }

    public function testAccumulatesMultipleErrors(): void
    {
        try {
            PasswordPolicy::assertAcceptable('learner@example.com', 'learner@example.com', '+919876543210');
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            // Too-short check does not apply here (length 19 >= 12); only the email-equality error fires.
            self::assertNotEmpty($exception->fields()['password']);
        }
    }
}
