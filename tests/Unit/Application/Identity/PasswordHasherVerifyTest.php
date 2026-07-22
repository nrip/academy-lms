<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Identity;

use Academy\Application\Identity\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherVerifyTest extends TestCase
{
    public function testVerifyAndNeedsRehashAgainstArgon2id(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('a-strong-password-verify-1');
        self::assertTrue(str_starts_with($hash, '$argon2id$'));
        self::assertTrue($hasher->verify('a-strong-password-verify-1', $hash));
        self::assertFalse($hasher->verify('wrong-password-xxxxx', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testDummyVerifyAlwaysRunsCrypto(): void
    {
        $hasher = new PasswordHasher();
        self::assertTrue(str_starts_with($hasher->dummyHash(), '$argon2id$'));
        $hasher->verifyDummy('any-password-input');
        self::assertTrue(password_verify('academy-lms-dummy-password-timing-pad', $hasher->dummyHash()));
    }
}
