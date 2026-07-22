<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\ProfileGender;
use PHPUnit\Framework\TestCase;

final class ProfileGenderTest extends TestCase
{
    public function testNullReturnsNull(): void
    {
        self::assertNull(ProfileGender::normalize(null));
    }

    public function testEmptyStringReturnsNull(): void
    {
        self::assertNull(ProfileGender::normalize('   '));
    }

    public function testAllowedValuesNormalized(): void
    {
        self::assertSame('female', ProfileGender::normalize('Female'));
        self::assertSame('male', ProfileGender::normalize('  MALE '));
        self::assertSame('other', ProfileGender::normalize('other'));
        self::assertSame('prefer_not_to_say', ProfileGender::normalize('prefer_not_to_say'));
    }

    public function testUnsupportedValueRejected(): void
    {
        $this->expectException(ValidationException::class);
        ProfileGender::normalize('unicorn');
    }
}
