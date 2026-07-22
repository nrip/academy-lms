<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Identity;

use Academy\Domain\Identity\OtpHmac;
use PHPUnit\Framework\TestCase;

final class OtpHmacTest extends TestCase
{
    public function testHashOtpRoundTripEquals(): void
    {
        $hmac = new OtpHmac('unit-otp-pepper');
        $nonce = random_bytes(16);
        $otp = '123456';
        $hash = $hmac->hashOtp($nonce, $otp);

        self::assertSame(64, strlen($hash));
        self::assertTrue($hmac->equals($hash, $hmac->hashOtp($nonce, $otp)));
        self::assertFalse($hmac->equals($hash, $hmac->hashOtp($nonce, '654321')));
        self::assertFalse($hmac->equals($hash, $hmac->hashOtp(random_bytes(16), $otp)));
    }

    public function testHashDestination(): void
    {
        $hmac = new OtpHmac('unit-otp-pepper');
        $dest = '+919876543210';
        $hash = $hmac->hashDestination($dest);

        self::assertSame(64, strlen($hash));
        self::assertTrue($hmac->equals($hash, $hmac->hashDestination($dest)));
        self::assertFalse($hmac->equals($hash, $hmac->hashDestination('+919876543211')));
    }
}
