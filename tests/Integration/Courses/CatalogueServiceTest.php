<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Courses;

use Academy\Application\Courses\CatalogueService;
use Academy\Domain\Courses\BatchAvailabilityEvaluator;
use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\CourseStatus;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Exception\NotFoundException;
use Academy\Infrastructure\Courses\PdoBatchRepository;
use Academy\Infrastructure\Courses\PdoCourseDocumentRequirementRepository;
use Academy\Infrastructure\Courses\PdoCourseRepository;
use Academy\Infrastructure\Courses\PdoCourseVersionRepository;
use Academy\Infrastructure\Courses\PdoEligibilityRuleRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class CatalogueServiceTest extends TestCase
{
    private CatalogueService $service;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $connections = DatabaseTestCase::connectionFactory();
        $this->service = new CatalogueService(
            new PdoCourseRepository($connections),
            new PdoCourseVersionRepository($connections),
            new PdoBatchRepository($connections),
            new PdoEligibilityRuleRepository($connections),
            new PdoCourseDocumentRequirementRepository($connections),
            new BatchAvailabilityEvaluator(),
        );
    }

    public function testListPublishedCoursesReturnsOnlyPublishedActiveCourses(): void
    {
        $published = DatabaseTestCase::seedPublishedCourse();
        DatabaseTestCase::seedPublishedCourse(['course_status' => CourseStatus::RETIRED]);
        DatabaseTestCase::seedPublishedCourse(['version_status' => CourseVersionStatus::DRAFT, 'locked' => false, 'set_current_published_version' => false]);

        $courses = $this->service->listPublishedCourses();

        self::assertCount(1, $courses);
        self::assertSame($published['course_id'], $courses[0]['course']->courseId);
    }

    public function testGetPublishedCourseBySlugReturnsCourseAndVersion(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['slug' => 'catalogue-test-slug']);

        $found = $this->service->getPublishedCourseBySlug('catalogue-test-slug');

        self::assertSame($seeded['course_id'], $found['course']->courseId);
        self::assertSame($seeded['version_id'], $found['version']->versionId);
    }

    public function testGetPublishedCourseBySlugThrowsNotFoundForUnknownSlug(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->getPublishedCourseBySlug('does-not-exist');
    }

    public function testGetPublishedCourseBySlugThrowsNotFoundForUnpublishedVersion(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse([
            'slug' => 'hidden-draft-course',
            'version_status' => CourseVersionStatus::DRAFT,
            'locked' => false,
            'set_current_published_version' => false,
        ]);
        unset($seeded);

        $this->expectException(NotFoundException::class);
        $this->service->getPublishedCourseBySlug('hidden-draft-course');
    }

    public function testGetPublishedCourseBySlugThrowsNotFoundForRetiredCourse(): void
    {
        DatabaseTestCase::seedPublishedCourse([
            'slug' => 'retired-course',
            'course_status' => CourseStatus::RETIRED,
        ]);

        $this->expectException(NotFoundException::class);
        $this->service->getPublishedCourseBySlug('retired-course');
    }

    public function testGetCourseDetailIncludesEligibilityRulesDocumentsAndBatches(): void
    {
        // Insert rule/document rows before the version is locked: the
        // immutability triggers forbid adding children to a locked version.
        $seeded = DatabaseTestCase::seedPublishedCourse([
            'slug' => 'detail-course',
            'locked' => false,
            'set_current_published_version' => false,
        ]);
        $this->insertEligibilityRule($seeded['version_id']);
        $this->insertDocumentRequirement($seeded['version_id']);
        $this->lockVersion($seeded['version_id'], $seeded['course_id']);
        DatabaseTestCase::seedBatch($seeded['version_id']);

        $detail = $this->service->getCourseDetail('detail-course');

        self::assertCount(1, $detail['eligibilityRules']);
        self::assertCount(1, $detail['documentRequirements']);
        self::assertCount(1, $detail['batches']);
        self::assertTrue($detail['batches'][0]['availability']->selectable);
    }

    public function testListBatchesForCourseSlugOrdersByApplicationsOpenAt(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['slug' => 'batches-list-course']);
        $laterBatch = DatabaseTestCase::seedBatch($seeded['version_id'], [
            'applications_open_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s.u'),
        ]);
        $earlierBatch = DatabaseTestCase::seedBatch($seeded['version_id'], [
            'applications_open_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-10 days')->format('Y-m-d H:i:s.u'),
        ]);

        $found = $this->service->listBatchesForCourseSlug('batches-list-course');

        self::assertSame($earlierBatch, $found['batches'][0]['batch']->batchId);
        self::assertSame($laterBatch, $found['batches'][1]['batch']->batchId);
    }

    public function testGetBatchReturnsAvailabilityForFullBatch(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id'], ['status' => BatchStatus::FULL]);

        $found = $this->service->getBatch($batchId);

        self::assertFalse($found['availability']->selectable);
    }

    public function testGetBatchThrowsNotFoundWhenMissing(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->getBatch(999999);
    }

    public function testGetBatchThrowsNotFoundWhenParentCourseRetired(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['course_status' => CourseStatus::RETIRED]);
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id']);

        $this->expectException(NotFoundException::class);
        $this->service->getBatch($batchId);
    }

    private function lockVersion(int $versionId, int $courseId): void
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo->prepare(
            'UPDATE course_versions SET locked_at = :locked_at, locked_reason = :locked_reason, updated_at = :updated_at
             WHERE version_id = :id',
        )->execute(['locked_at' => $now, 'locked_reason' => 'published', 'updated_at' => $now, 'id' => $versionId]);
        $pdo->prepare('UPDATE courses SET current_published_version_id = :version_id WHERE course_id = :course_id')
            ->execute(['version_id' => $versionId, 'course_id' => $courseId]);
    }

    private function insertEligibilityRule(int $versionId): void
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO eligibility_rules (
                course_version_id, field, operator, value, logic_group, display_label, sort_order, created_at, updated_at
            ) VALUES (:version_id, :field, :operator, :value, :logic_group, :label, 1, :created_at, :updated_at)',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'field' => 'profession',
            'operator' => 'in',
            'value' => 'doctor',
            'logic_group' => 'AND',
            'label' => 'Must be a doctor.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertDocumentRequirement(int $versionId): void
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO course_document_requirements (
                course_version_id, document_name, description, mandatory_flag, accepted_file_types,
                max_size_bytes, single_or_multiple, reuse_allowed, reviewer_instructions, sort_order,
                created_at, updated_at
            ) VALUES (
                :version_id, :name, :description, 1, :types, 1048576, :single, 0, NULL, 1, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'name' => 'Registration certificate',
            'description' => 'Scan of registration certificate.',
            'types' => 'pdf,jpg',
            'single' => 'single',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
