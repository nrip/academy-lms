<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\QualificationValidator;
use PHPUnit\Framework\TestCase;

final class QualificationValidatorTest extends TestCase
{
    private QualificationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new QualificationValidator();
    }

    public function testValidPayloadNormalized(): void
    {
        $result = $this->validator->validate([
            'qualification_type' => '  Degree ',
            'qualification_name' => 'MBBS',
            'institution_name' => 'AIIMS',
            'completion_year' => '2010',
            'university_or_board' => '',
        ]);

        self::assertSame('Degree', $result['qualification_type']);
        self::assertSame('MBBS', $result['qualification_name']);
        self::assertSame('AIIMS', $result['institution_name']);
        self::assertSame(2010, $result['completion_year']);
        self::assertNull($result['university_or_board']);
    }

    public function testMissingRequiredFieldsRejected(): void
    {
        try {
            $this->validator->validate(['completion_year' => '2010']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('qualification_type', $exception->fields());
            self::assertArrayHasKey('qualification_name', $exception->fields());
            self::assertArrayHasKey('institution_name', $exception->fields());
        }
    }

    public function testCompletionYearBelowMinimumRejected(): void
    {
        try {
            $this->validator->validate([
                'qualification_type' => 'Degree',
                'qualification_name' => 'MBBS',
                'institution_name' => 'AIIMS',
                'completion_year' => '1949',
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('completion_year', $exception->fields());
        }
    }

    public function testCompletionYearTooFarInFutureRejected(): void
    {
        $tooFar = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y') + 2;
        try {
            $this->validator->validate([
                'qualification_type' => 'Degree',
                'qualification_name' => 'MBBS',
                'institution_name' => 'AIIMS',
                'completion_year' => (string) $tooFar,
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('completion_year', $exception->fields());
        }
    }

    public function testCompletionYearNextYearAccepted(): void
    {
        $nextYear = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y') + 1;
        $result = $this->validator->validate([
            'qualification_type' => 'Degree',
            'qualification_name' => 'MBBS',
            'institution_name' => 'AIIMS',
            'completion_year' => (string) $nextYear,
        ]);
        self::assertSame($nextYear, $result['completion_year']);
    }

    public function testOverlongOptionalFieldRejected(): void
    {
        try {
            $this->validator->validate([
                'qualification_type' => 'Degree',
                'qualification_name' => 'MBBS',
                'institution_name' => 'AIIMS',
                'completion_year' => '2010',
                'country' => str_repeat('a', 101),
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('country', $exception->fields());
        }
    }
}
