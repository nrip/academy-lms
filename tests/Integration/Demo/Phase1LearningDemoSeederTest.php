<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Demo;

use Academy\Application\Ops\UatSeedService;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class Phase1LearningDemoSeederTest extends TestCase
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

    public function testPhase1SeederCreatesCurriculumBatchAndUatSeedWiresLearnerPaths(): void
    {
        DatabaseTestCase::runSeeder('Wp02DemoCatalogueSeeder');
        DatabaseTestCase::runSeeder('WpL9Phase1LearningDemoSeeder');
        DatabaseTestCase::runSeeder('WpL9Phase1LearningDemoSeeder'); // idempotent

        $pdo = DatabaseTestCase::pdo();
        $courseId = (int) $pdo->query(
            "SELECT course_id FROM courses WHERE course_code = 'PHASE1-DEMO-CME-101'",
        )->fetchColumn();
        self::assertGreaterThan(0, $courseId);

        $moduleCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM modules m
             INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE c.course_code = \'PHASE1-DEMO-CME-101\'',
        )->fetchColumn();
        self::assertSame(2, $moduleCount);

        $contentTypes = $pdo->query(
            'SELECT ci.content_type FROM content_items ci
             INNER JOIN modules m ON m.module_id = ci.module_id
             INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE c.course_code = \'PHASE1-DEMO-CME-101\'
             ORDER BY m.sequence, ci.sequence',
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['video', 'text_lesson', 'mcq_assessment'], $contentTypes);

        $assessmentLinks = (int) $pdo->query(
            'SELECT COUNT(*) FROM assessment_question_links aql
             INNER JOIN assessments a ON a.assessment_id = aql.assessment_id
             INNER JOIN content_items ci ON ci.content_id = a.content_id
             INNER JOIN modules m ON m.module_id = ci.module_id
             INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             WHERE c.course_code = \'PHASE1-DEMO-CME-101\'',
        )->fetchColumn();
        self::assertSame(5, $assessmentLinks);

        $batchStarts = $pdo->query(
            "SELECT starts_at < UTC_TIMESTAMP(6) FROM batches WHERE batch_code = 'PHASE1-DEMO-CME-101-ACTIVE'",
        )->fetchColumn();
        self::assertSame(1, (int) $batchStarts);

        $container = ApplicationFactory::container('testing');
        $seed = $container->get(UatSeedService::class);
        $result = $seed->seed();
        self::assertTrue($result['catalogue']);
        self::assertContains('phase1:learner-active-enrolment=ok', $result['summary']);
        self::assertContains('phase1:learner-progress=ready-for-video', $result['summary']);
        self::assertContains('phase1:certificate-scenario=issued', $result['summary']);

        $learnerId = (int) $pdo->query(
            "SELECT user_id FROM users WHERE email = 'learner@uat.example.test'",
        )->fetchColumn();
        $enrolment = $pdo->query(
            'SELECT e.enrolment_id, e.lifecycle_status FROM enrolments e
             INNER JOIN applications a ON a.application_id = e.application_id
             WHERE a.user_id = ' . $learnerId . "
               AND a.application_number = 'UAT-PHASE1-LEARN-001'",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($enrolment);
        self::assertSame('active', $enrolment['lifecycle_status']);

        $completed = (int) $pdo->query(
            'SELECT COUNT(*) FROM content_progress
             WHERE enrolment_id = ' . (int) $enrolment['enrolment_id'] . "
               AND completion_status = 'completed'",
        )->fetchColumn();
        self::assertSame(0, $completed);

        $certNumber = $pdo->query(
            "SELECT c.certificate_number FROM certificates c
             INNER JOIN enrolments e ON e.enrolment_id = c.enrolment_id
             INNER JOIN applications a ON a.application_id = e.application_id
             WHERE a.application_number = 'UAT-PHASE1-CERT-001'
               AND c.current_marker = 1",
        )->fetchColumn();
        self::assertNotFalse($certNumber);
        self::assertStringStartsWith('ACAD-DEMO-', (string) $certNumber);
    }
}
