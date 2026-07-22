<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Security;

use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class RateLimitKeyFactoryMobileTest extends TestCase
{
    public function testBucketKeyNeverContainsRawMobileDigitsAsSubstring(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $mobile = '9876543210';
        $normalized = $factory->normalizeMobile($mobile);
        $key = $factory->bucketKey('auth.otp_send.15m', 'mobile_e164', $normalized);

        self::assertSame(64, strlen($key));
        self::assertStringNotContainsString($mobile, $key);
        self::assertStringNotContainsString('+91', $key);
        self::assertStringNotContainsString($normalized, $key);
    }

    public function testBucketKeyNeverContainsRawE164DigitsAsSubstring(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $mobile = '+14155552671';
        $normalized = $factory->normalizeMobile($mobile);
        $key = $factory->bucketKey('auth.otp_verify', 'mobile_e164', $normalized);

        self::assertStringNotContainsString('14155552671', $key);
        self::assertStringNotContainsString($mobile, $key);
    }

    public function testMobileE164DimensionUsesNormalizedFormAndCorrectType(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $dimension = $factory->mobileE164Dimension('9876543210');

        self::assertSame('mobile_e164', $dimension['type']);
        self::assertSame('+919876543210', $dimension['value']);
    }

    public function testMobileE164DimensionNormalizesAlreadyE164Input(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $dimension = $factory->mobileE164Dimension('+919876543210');

        self::assertSame('+919876543210', $dimension['value']);
    }

    public function testMobileE164DimensionRejectsInvalidMobile(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $this->expectException(ValidationException::class);
        $factory->mobileE164Dimension('not-a-mobile');
    }

    public function testDifferentMobilesProduceDifferentBucketKeys(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $keyA = $factory->bucketKey(
            'auth.otp_send.15m',
            'mobile_e164',
            $factory->normalizeMobile('9876543210'),
        );
        $keyB = $factory->bucketKey(
            'auth.otp_send.15m',
            'mobile_e164',
            $factory->normalizeMobile('9876543211'),
        );

        self::assertNotSame($keyA, $keyB);
    }

    public function testSameMobileDifferentPoliciesProduceDifferentBucketKeys(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $normalized = $factory->normalizeMobile('9876543210');
        $keyA = $factory->bucketKey('auth.otp_send.15m', 'mobile_e164', $normalized);
        $keyB = $factory->bucketKey('auth.otp_send.24h', 'mobile_e164', $normalized);

        self::assertNotSame($keyA, $keyB);
    }
}
