<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Identity;

use Academy\Domain\Identity\TokenHmac;
use PHPUnit\Framework\TestCase;

final class TokenHmacTest extends TestCase
{
    public function testRoundTripHashAndEquals(): void
    {
        $hmac = new TokenHmac('unit-token-pepper');
        $raw = bin2hex(random_bytes(32));
        $hash = $hmac->hash($raw);

        self::assertSame(64, strlen($hash));
        self::assertTrue($hmac->equals($hash, $hmac->hash($raw)));
    }

    public function testTamperedHashRejectedViaEqualsFalse(): void
    {
        $hmac = new TokenHmac('unit-token-pepper');
        $raw = bin2hex(random_bytes(32));
        $hash = $hmac->hash($raw);
        $tampered = $hash;
        $tampered[0] = $tampered[0] === 'a' ? 'b' : 'a';

        self::assertFalse($hmac->equals($hash, $tampered));
        self::assertFalse($hmac->equals($hash, $hmac->hash($raw . 'x')));
    }

    public function testMissingTokenDimensionIsStableAndDistinct(): void
    {
        $hmac = new TokenHmac('unit-token-pepper');
        $raw = str_repeat('ab', 32);
        $a = $hmac->missingTokenDimension($raw);
        $b = $hmac->missingTokenDimension($raw);

        self::assertSame($a, $b);
        self::assertNotSame($hmac->hash($raw), $a);
    }
}
