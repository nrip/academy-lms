<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\LegalAcceptancePolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LegalAcceptancePolicyTest extends TestCase
{
    public function testAcceptedBothReturnsWithoutError(): void
    {
        $policy = new LegalAcceptancePolicy('2026-07-22', '2026-07-22');
        $policy->assertAccepted(true, true);
        self::assertTrue(true);
    }

    public function testExposesCurrentVersions(): void
    {
        $policy = new LegalAcceptancePolicy('2026-07-22', '2026-07-22');
        self::assertSame('2026-07-22', $policy->currentTermsVersion());
        self::assertSame('2026-07-22', $policy->currentPrivacyVersion());
    }

    public function testRejectsMissingTermsAcceptance(): void
    {
        $policy = new LegalAcceptancePolicy('2026-07-22', '2026-07-22');
        try {
            $policy->assertAccepted(false, true);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('terms_accepted', $exception->fields());
            self::assertArrayNotHasKey('privacy_accepted', $exception->fields());
        }
    }

    public function testRejectsMissingPrivacyAcceptance(): void
    {
        $policy = new LegalAcceptancePolicy('2026-07-22', '2026-07-22');
        try {
            $policy->assertAccepted(true, false);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('privacy_accepted', $exception->fields());
            self::assertArrayNotHasKey('terms_accepted', $exception->fields());
        }
    }

    public function testRejectsBothMissing(): void
    {
        $policy = new LegalAcceptancePolicy('2026-07-22', '2026-07-22');
        try {
            $policy->assertAccepted(false, false);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('terms_accepted', $exception->fields());
            self::assertArrayHasKey('privacy_accepted', $exception->fields());
        }
    }

    public function testConstructorRejectsEmptyTermsVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LegalAcceptancePolicy('', '2026-07-22');
    }

    public function testConstructorRejectsEmptyPrivacyVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LegalAcceptancePolicy('2026-07-22', '');
    }
}
