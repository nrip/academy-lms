<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\PersonalProfileValidator;
use PHPUnit\Framework\TestCase;

final class PersonalProfileValidatorTest extends TestCase
{
    private PersonalProfileValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PersonalProfileValidator();
    }

    public function testTrimsAndConvertsEmptyStringsToNull(): void
    {
        $result = $this->validator->validate([
            'first_name' => '  Asha  ',
            'middle_name' => '',
            'last_name' => 'Rao',
        ]);

        self::assertSame('Asha', $result['first_name']);
        self::assertNull($result['middle_name']);
        self::assertSame('Rao', $result['last_name']);
    }

    public function testAcceptsUnicodeNames(): void
    {
        $result = $this->validator->validate([
            'first_name' => 'अशा',
            'last_name' => 'کھان',
        ]);

        self::assertSame('अशा', $result['first_name']);
        self::assertSame('کھان', $result['last_name']);
    }

    public function testRejectsArrayForScalarField(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['first_name' => ['nested']]);
    }

    public function testRejectsOverlongName(): void
    {
        try {
            $this->validator->validate(['first_name' => str_repeat('a', 101)]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('first_name', $exception->fields());
        }
    }

    public function testRejectsFutureDateOfBirth(): void
    {
        $future = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        try {
            $this->validator->validate(['date_of_birth' => $future]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('date_of_birth', $exception->fields());
        }
    }

    public function testRejectsMalformedDateOfBirth(): void
    {
        try {
            $this->validator->validate(['date_of_birth' => '13/07/1990']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('date_of_birth', $exception->fields());
        }
    }

    public function testAcceptsPastDateOfBirth(): void
    {
        $result = $this->validator->validate(['date_of_birth' => '1990-07-13']);
        self::assertSame('1990-07-13', $result['date_of_birth']);
    }

    public function testRejectsInvalidGender(): void
    {
        try {
            $this->validator->validate(['gender' => 'invalid']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('gender', $exception->fields());
        }
    }

    public function testAlternateMobileNormalizedToE164(): void
    {
        $result = $this->validator->validate(['alternate_mobile' => '9876543210']);
        self::assertSame('+919876543210', $result['alternate_mobile']);
    }

    public function testInvalidAlternateMobileMapsToAlternateMobileFieldKey(): void
    {
        try {
            $this->validator->validate(['alternate_mobile' => 'not-a-number']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('alternate_mobile', $exception->fields());
            self::assertArrayNotHasKey('mobile', $exception->fields());
        }
    }

    public function testIndianPostalCodeRequiresSixDigits(): void
    {
        try {
            $this->validator->validate(['country' => 'India', 'postal_code' => '123']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('postal_code', $exception->fields());
        }
    }

    public function testIndianPostalCodeAcceptsSixDigits(): void
    {
        $result = $this->validator->validate(['country' => 'in', 'postal_code' => '560001']);
        self::assertSame('560001', $result['postal_code']);
        self::assertSame('in', $result['country']);
    }

    public function testNonIndianPostalCodeNotConstrained(): void
    {
        $result = $this->validator->validate(['country' => 'United Kingdom', 'postal_code' => 'SW1A 1AA']);
        self::assertSame('SW1A 1AA', $result['postal_code']);
    }

    public function testCertificateNameConfirmedBool(): void
    {
        $confirmed = $this->validator->validate(['certificate_name_confirmed' => '1']);
        self::assertTrue($confirmed['certificate_name_confirmed']);

        $unconfirmed = $this->validator->validate([]);
        self::assertFalse($unconfirmed['certificate_name_confirmed']);
    }

    public function testRejectsArrayForCertificateNameConfirmed(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['certificate_name_confirmed' => ['x']]);
    }

    public function testReturnsOnlyPersonalKeys(): void
    {
        $result = $this->validator->validate(['first_name' => 'A', 'unsupported_field' => 'x']);
        self::assertArrayNotHasKey('unsupported_field', $result);
        self::assertArrayHasKey('first_name', $result);
    }
}
