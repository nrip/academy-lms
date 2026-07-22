<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\CourseStatus;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class CourseCatalogueHttpTest extends TestCase
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
    }

    public function testIndexListsPublishedCourse(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['slug' => 'http-index-course', 'title' => 'HTTP Index Course']);
        DatabaseTestCase::seedBatch($seeded['version_id']);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses', 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('HTTP Index Course', (string) $response->getBody());
    }

    public function testIndexOmitsRetiredCourse(): void
    {
        DatabaseTestCase::seedPublishedCourse([
            'slug' => 'http-retired-course',
            'title' => 'HTTP Retired Course',
            'course_status' => CourseStatus::RETIRED,
        ]);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses', 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('HTTP Retired Course', (string) $response->getBody());
    }

    public function testShowReturnsCourseDetail(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['slug' => 'http-show-course', 'title' => 'HTTP Show Course']);
        DatabaseTestCase::seedBatch($seeded['version_id']);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses/http-show-course', 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('HTTP Show Course', (string) $response->getBody());
    }

    public function testShowReturns404ForUnknownSlug(): void
    {
        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses/does-not-exist', 'GET'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testShowReturns404ForUnpublishedCourse(): void
    {
        DatabaseTestCase::seedPublishedCourse([
            'slug' => 'http-draft-course',
            'version_status' => CourseVersionStatus::DRAFT,
            'locked' => false,
            'set_current_published_version' => false,
        ]);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses/http-draft-course', 'GET'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testBatchesListsBatchesForCourse(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['slug' => 'http-batches-course']);
        DatabaseTestCase::seedBatch($seeded['version_id'], ['batch_code' => 'HTTP-BATCHES-1']);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses/http-batches-course/batches', 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBatchShowReturnsBatchDetail(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['slug' => 'http-batch-detail-course']);
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id'], ['name' => 'HTTP Batch Detail Cohort']);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/batches/' . $batchId, 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('HTTP Batch Detail Cohort', (string) $response->getBody());
    }

    public function testBatchShowReturns404ForFullBatchIsStillVisibleButNotSelectable(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['slug' => 'http-full-batch-course']);
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id'], ['status' => BatchStatus::FULL]);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/batches/' . $batchId, 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBatchShowReturns404ForUnknownBatch(): void
    {
        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/batches/999999', 'GET'),
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
