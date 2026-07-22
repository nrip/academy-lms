<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Courses;

use Academy\Application\Courses\CatalogueService;
use Academy\Infrastructure\Courses\PdoBatchRepository;
use Academy\Infrastructure\Courses\PdoCourseDocumentRequirementRepository;
use Academy\Infrastructure\Courses\PdoCourseRepository;
use Academy\Infrastructure\Courses\PdoCourseVersionRepository;
use Academy\Infrastructure\Courses\PdoEligibilityRuleRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DemoCatalogueSeederTest extends TestCase
{
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
        $this->factory = DatabaseTestCase::connectionFactory();
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
    }

    public function testSeederCreatesTwoDemoCoursesWithBatches(): void
    {
        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');

        $pdo = DatabaseTestCase::pdo();
        $courseCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM courses WHERE course_code LIKE 'WP02-DEMO-%'",
        )->fetchColumn();
        self::assertSame(2, $courseCount);

        $batchStatuses = $pdo->query(
            "SELECT b.status FROM batches b
             INNER JOIN course_versions cv ON cv.version_id = b.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE c.course_code = 'WP02-DEMO-OBESITY-101'",
        )->fetchAll(\PDO::FETCH_COLUMN);

        sort($batchStatuses);
        self::assertSame(['completed', 'full', 'open_for_applications', 'planned'], $batchStatuses);
    }

    public function testSeederIsIdempotentWhenRunTwice(): void
    {
        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');
        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');

        $pdo = DatabaseTestCase::pdo();
        $courseCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM courses WHERE course_code LIKE 'WP02-DEMO-%'",
        )->fetchColumn();
        self::assertSame(2, $courseCount);

        $batchCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM batches b
             INNER JOIN course_versions cv ON cv.version_id = b.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE c.course_code LIKE 'WP02-DEMO-%'",
        )->fetchColumn();
        self::assertSame(5, $batchCount);
    }

    public function testSeederRefusesToRunInProduction(): void
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never run in production');

        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');
    }

    public function testSeederRefusesToRunInStaging(): void
    {
        putenv('APP_ENV=staging');
        $_ENV['APP_ENV'] = 'staging';
        $_SERVER['APP_ENV'] = 'staging';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never run in staging');

        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');
    }

    public function testSeededCoursesAreDiscoverableViaCatalogueService(): void
    {
        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');

        $service = new CatalogueService(
            new PdoCourseRepository($this->factory),
            new PdoCourseVersionRepository($this->factory),
            new PdoBatchRepository($this->factory),
            new PdoEligibilityRuleRepository($this->factory),
            new PdoCourseDocumentRequirementRepository($this->factory),
            new \Academy\Domain\Courses\BatchAvailabilityEvaluator(),
        );

        $courses = $service->listPublishedCourses();
        $slugs = array_map(static fn (array $row) => $row['course']->slug, $courses);

        self::assertContains('obesity-management-foundations', $slugs);
        self::assertContains('metabolic-health-advanced', $slugs);

        $detail = $service->getCourseDetail('obesity-management-foundations');
        self::assertNotEmpty($detail['eligibilityRules']);
        self::assertNotEmpty($detail['documentRequirements']);
        self::assertCount(4, $detail['batches']);
    }
}
