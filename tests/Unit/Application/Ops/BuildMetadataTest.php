<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Ops;

use Academy\Application\Ops\BuildMetadata;
use PHPUnit\Framework\TestCase;

final class BuildMetadataTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_VERSION');
        putenv('GIT_SHA');
        putenv('BUILD_TIME');
        unset($_ENV['APP_VERSION'], $_ENV['GIT_SHA'], $_ENV['BUILD_TIME']);
        parent::tearDown();
    }

    public function testFromEnvironmentUsesInjectedMetadata(): void
    {
        putenv('APP_VERSION=1.2.3');
        $_ENV['APP_VERSION'] = '1.2.3';
        putenv('GIT_SHA=abc123def456');
        $_ENV['GIT_SHA'] = 'abc123def456';
        putenv('BUILD_TIME=2026-07-24T01:00:00Z');
        $_ENV['BUILD_TIME'] = '2026-07-24T01:00:00Z';

        $meta = BuildMetadata::fromEnvironment(['app' => ['env' => 'uat']], '20260724000001');
        $array = $meta->toArray();

        self::assertSame('1.2.3', $array['application_version']);
        self::assertSame('abc123def456', $array['commit_sha']);
        self::assertSame('2026-07-24T01:00:00Z', $array['build_time']);
        self::assertSame('uat', $array['environment']);
        self::assertSame('20260724000001', $array['schema_version']);
        self::assertStringNotContainsString('/', json_encode($array, JSON_THROW_ON_ERROR));
    }
}
