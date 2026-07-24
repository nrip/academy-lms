<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Ops;

use Academy\Application\Ops\AppEnvironment;
use Academy\Application\Ops\EnvironmentCapability;
use PHPUnit\Framework\TestCase;

final class EnvironmentCapabilityTest extends TestCase
{
    public function testSupportedModes(): void
    {
        foreach (['local', 'testing', 'ci', 'uat', 'staging', 'production'] as $name) {
            self::assertSame($name, EnvironmentCapability::fromEnvName($name)->name());
        }
    }

    public function testUatIsNotProductionLikeButAllowsFakeAdapters(): void
    {
        $uat = EnvironmentCapability::fromEnvName('uat');
        self::assertFalse($uat->isProductionLike());
        self::assertTrue($uat->allowsFakeOrLocalAdapters());
        self::assertTrue($uat->allowsUatSeedAndReset());
        self::assertTrue($uat->allowsDotenvFile());
        self::assertFalse($uat->allowsSoftSecretDefaults());
        self::assertFalse($uat->allowsDebugDetails());
        self::assertFalse($uat->defaultFakePaymentGatewayEnabled());
    }

    public function testProductionLikeFailsClosedForFakes(): void
    {
        foreach (['staging', 'production'] as $name) {
            $cap = EnvironmentCapability::fromEnvName($name);
            self::assertTrue($cap->isProductionLike());
            self::assertFalse($cap->allowsFakeOrLocalAdapters());
            self::assertFalse($cap->allowsUatSeedAndReset());
            self::assertFalse($cap->allowsDotenvFile());
        }
    }

    public function testLocalSoftDefaults(): void
    {
        $local = new EnvironmentCapability(AppEnvironment::Local);
        self::assertTrue($local->allowsSoftSecretDefaults());
        self::assertTrue($local->allowsDebugDetails());
        self::assertSame('local_file', $local->defaultEmailAdapter());
    }

    public function testUnknownEnvNameFailsClosedLikeProduction(): void
    {
        $cap = EnvironmentCapability::fromEnvName('demo');
        self::assertTrue($cap->isProductionLike());
        self::assertFalse($cap->allowsFakeOrLocalAdapters());
        self::assertFalse($cap->allowsUatSeedAndReset());
    }
}
