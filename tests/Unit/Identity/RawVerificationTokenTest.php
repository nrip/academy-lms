<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\RawVerificationToken;
use PHPUnit\Framework\TestCase;

final class RawVerificationTokenTest extends TestCase
{
    public function testExactlySixtyFourHexAcceptedAndLowercased(): void
    {
        $upper = strtoupper(bin2hex(random_bytes(32)));
        $parsed = RawVerificationToken::parse($upper);

        self::assertSame(strtolower($upper), $parsed->value());
        self::assertSame(64, strlen($parsed->value()));
    }

    public function testRejectsShortToken(): void
    {
        $this->expectException(ValidationException::class);
        RawVerificationToken::parse(str_repeat('a', 63));
    }

    public function testRejectsLongToken(): void
    {
        $this->expectException(ValidationException::class);
        RawVerificationToken::parse(str_repeat('a', 65));
    }

    public function testRejectsNonHex(): void
    {
        $this->expectException(ValidationException::class);
        RawVerificationToken::parse(str_repeat('g', 64));
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(ValidationException::class);
        RawVerificationToken::parse('');
    }
}
