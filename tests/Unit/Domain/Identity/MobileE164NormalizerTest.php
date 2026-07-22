<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\MobileE164Normalizer;
use PHPUnit\Framework\TestCase;

final class MobileE164NormalizerTest extends TestCase
{
    public function testTenDigitIndianMobileGetsPlusNinetyOnePrefix(): void
    {
        self::assertSame('+919876543210', MobileE164Normalizer::normalize('9876543210'));
    }

    public function testTenDigitIndianMobileWithSurroundingWhitespace(): void
    {
        self::assertSame('+919876543210', MobileE164Normalizer::normalize('  9876543210  '));
    }

    public function testValidE164IsAcceptedAsIs(): void
    {
        self::assertSame('+14155552671', MobileE164Normalizer::normalize('+14155552671'));
    }

    public function testValidE164MinimumLengthIsAccepted(): void
    {
        // +[1-9]\d{7,14} — minimum total significant digits after the leading digit is 8.
        self::assertSame('+12345678', MobileE164Normalizer::normalize('+12345678'));
    }

    public function testRejectsAmbiguousNationalNumberThatIsNotTenDigits(): void
    {
        // 9-digit "national" style input is neither a valid 10-digit Indian mobile
        // nor a valid E.164 (+...) form — must be rejected, not guessed.
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('987654321');
    }

    public function testRejectsElevenDigitAmbiguousNationalNumber(): void
    {
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('09876543210');
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('');
    }

    public function testRejectsAlphaCharacters(): void
    {
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('98765x4321');
    }

    public function testRejectsExtensionMarkerSuffix(): void
    {
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('+14155552671;ext=123');
    }

    public function testRejectsHashCharacter(): void
    {
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('+14155552671#123');
    }

    public function testRejectsPlusZeroLeadingDigit(): void
    {
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('+0123456789');
    }

    public function testRejectsMissingPlusForNonTenDigitForm(): void
    {
        $this->expectException(ValidationException::class);
        MobileE164Normalizer::normalize('14155552671');
    }

    public function testValidationExceptionCarriesMobileField(): void
    {
        try {
            MobileE164Normalizer::normalize('bad');
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('mobile', $exception->fields());
        }
    }
}
