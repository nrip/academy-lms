<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Ops;

use Academy\Application\Ops\UatResetService;
use Academy\Application\Ops\UatSeedService;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class UatSeedResetIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');
    }

    public function testSeedIsIdempotentAndResetRemovesUatRows(): void
    {
        $container = ApplicationFactory::container('testing');
        $seed = $container->get(UatSeedService::class);

        $first = $seed->seed();
        $second = $seed->seed();

        self::assertTrue($first['catalogue']);
        self::assertSame($first['personas'], $second['personas']);
        self::assertSame($first['applications'], $second['applications']);
        self::assertGreaterThanOrEqual(5, $first['personas']);
        self::assertGreaterThanOrEqual(4, $first['notifications']);

        $pdo = DatabaseTestCase::pdo();
        $learners = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE email LIKE '%@uat.example.test'",
        )->fetchColumn();
        self::assertGreaterThanOrEqual(5, $learners);

        $reset = $container->get(UatResetService::class)->reset(true);
        self::assertGreaterThan(0, $reset['deleted_users']);

        $after = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE email LIKE '%@uat.example.test'",
        )->fetchColumn();
        self::assertSame(0, $after);

        $reseed = $seed->seed();
        self::assertGreaterThanOrEqual(5, $reseed['personas']);
    }
}
