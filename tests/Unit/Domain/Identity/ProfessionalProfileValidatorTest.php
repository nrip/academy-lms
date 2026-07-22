<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\ProfessionalProfileValidator;
use PHPUnit\Framework\TestCase;

final class ProfessionalProfileValidatorTest extends TestCase
{
    private ProfessionalProfileValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ProfessionalProfileValidator();
    }

    public function testNormalizesStringsAndExperience(): void
    {
        $result = $this->validator->validate([
            'profession' => '  Physician ',
            'years_of_experience' => '12',
            'organization_name' => '',
        ]);

        self::assertSame('Physician', $result['profession']);
        self::assertSame(12, $result['years_of_experience']);
        self::assertNull($result['organization_name']);
    }

    public function testExperienceZeroIsValid(): void
    {
        $result = $this->validator->validate(['years_of_experience' => '0']);
        self::assertSame(0, $result['years_of_experience']);
    }

    public function testExperienceAboveRangeRejected(): void
    {
        try {
            $this->validator->validate(['years_of_experience' => '71']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('years_of_experience', $exception->fields());
        }
    }

    public function testExperienceNonNumericRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['years_of_experience' => 'ten']);
    }

    public function testEmptyExperienceIsNull(): void
    {
        $result = $this->validator->validate(['years_of_experience' => '']);
        self::assertNull($result['years_of_experience']);
    }

    public function testValidUntilBeforeValidFromRejected(): void
    {
        try {
            $this->validator->validate([
                'registration_valid_from' => '2025-01-01',
                'registration_valid_until' => '2024-01-01',
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('registration_valid_until', $exception->fields());
        }
    }

    public function testValidUntilEqualToValidFromAccepted(): void
    {
        $result = $this->validator->validate([
            'registration_valid_from' => '2025-01-01',
            'registration_valid_until' => '2025-01-01',
        ]);
        self::assertSame('2025-01-01', $result['registration_valid_from']);
        self::assertSame('2025-01-01', $result['registration_valid_until']);
    }

    public function testMalformedDateRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['registration_valid_from' => '2025-13-40']);
    }

    public function testRejectsArrayForScalarField(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['profession' => ['x']]);
    }
}
