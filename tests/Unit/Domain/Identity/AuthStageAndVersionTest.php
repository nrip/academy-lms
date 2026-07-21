<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\AuthVersion;
use PHPUnit\Framework\TestCase;

final class AuthStageAndVersionTest extends TestCase
{
    public function testNonPrivilegedMissingStageInfersFullyAuthenticated(): void
    {
        self::assertSame(
            AuthStage::FULLY_AUTHENTICATED,
            AuthStage::resolveEffective(null, false),
        );
        self::assertSame(
            AuthStage::FULLY_AUTHENTICATED,
            AuthStage::resolveEffective('bogus', false),
        );
    }

    public function testPrivilegedMissingOrInvalidStageFailsClosedToEnrolmentRequired(): void
    {
        self::assertSame(
            AuthStage::MFA_ENROLMENT_REQUIRED,
            AuthStage::resolveEffective(null, true),
        );
        self::assertSame(
            AuthStage::MFA_ENROLMENT_REQUIRED,
            AuthStage::resolveEffective('unknown', true),
        );
        self::assertSame(
            AuthStage::MFA_ENROLMENT_REQUIRED,
            AuthStage::resolveEffective(AuthStage::ANONYMOUS, true),
        );
    }

    public function testPrivilegedKnownStagesPreservedAsIs(): void
    {
        self::assertSame(
            AuthStage::MFA_CHALLENGE_REQUIRED,
            AuthStage::resolveEffective(AuthStage::MFA_CHALLENGE_REQUIRED, true),
        );
        self::assertSame(
            AuthStage::FULLY_AUTHENTICATED,
            AuthStage::resolveEffective(AuthStage::FULLY_AUTHENTICATED, true),
        );
    }

    public function testAuthVersionRejectsValuesAbovePhpIntMax(): void
    {
        $this->expectException(\DomainException::class);
        AuthVersion::fromDatabase('9223372036854775808');
    }

    public function testAuthVersionAcceptsPhpIntMax(): void
    {
        self::assertSame(PHP_INT_MAX, AuthVersion::fromDatabase((string) PHP_INT_MAX));
    }
}
