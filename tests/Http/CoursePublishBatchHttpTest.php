<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CoursePublishBatchHttpTest extends TestCase
{
    private string $sessionCookieName;
    private string $csrfCookieName;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        DatabaseTestCase::migrate();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testPublishCloneBatchAndCatalogueFlow(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $suffix = bin2hex(random_bytes(3));
        $slug = 'pub-demo-' . $suffix;
        $create = $this->request('POST', '/admin/courses', $boot, [
            'course_code' => 'PUB-' . strtoupper($suffix),
            'slug' => $slug,
            'master_title' => 'Publish Demo ' . $suffix,
        ]);
        self::assertSame(303, $create->getStatusCode());
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);
        $courseId = (int) $m[1];
        $versionId = (int) $m[2];

        $incomplete = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/publish', $boot);
        self::assertSame(422, $incomplete->getStatusCode());

        $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId, $boot, [
            'title' => 'Publish Demo Version',
            'description' => 'Clinician-facing overview of metabolic health.',
            'learning_objectives' => 'Apply metabolic assessment in practice.',
            'intended_audience' => 'Doctors and allied health professionals.',
            'syllabus_summary' => 'Eight-week structured syllabus.',
            'delivery_type' => 'online',
            'duration_text' => '8 weeks',
            'validity_period_days' => '365',
            'standard_fee' => '12000.00',
            'gst_rate' => '18.00',
            'currency' => 'INR',
            'certificate_type' => 'Certificate of Completion',
        ]);

        $mod = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules', $boot, [
            'title' => 'Module 1',
            'description' => 'Foundations',
            'release_rule' => 'immediate',
            'mandatory_flag' => '1',
        ]);
        self::assertSame(303, $mod->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $moduleId = (int) $pdo->query(
            'SELECT module_id FROM modules WHERE course_version_id = ' . $versionId . ' LIMIT 1',
        )->fetchColumn();

        $content = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            [
                'content_type' => 'text_lesson',
                'title' => 'Intro lesson',
                'body_text' => 'Welcome to the course.',
            ],
        );
        self::assertSame(303, $content->getStatusCode());

        $publish = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/publish', $boot);
        self::assertSame(303, $publish->getStatusCode());
        self::assertSame(
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '?published=1',
            $publish->getHeaderLine('Location'),
        );

        $versionRow = $pdo->prepare('SELECT status, locked_at, locked_reason FROM course_versions WHERE version_id = :id');
        $versionRow->execute(['id' => $versionId]);
        $row = $versionRow->fetch();
        self::assertSame(CourseVersionStatus::PUBLISHED, $row['status']);
        self::assertNotNull($row['locked_at']);
        self::assertSame('published', $row['locked_reason']);

        $courseRow = $pdo->prepare('SELECT current_published_version_id FROM courses WHERE course_id = :id');
        $courseRow->execute(['id' => $courseId]);
        self::assertSame($versionId, (int) $courseRow->fetchColumn());

        $history = $pdo->prepare(
            'SELECT to_status FROM course_version_status_history WHERE version_id = :id ORDER BY history_id DESC LIMIT 1',
        );
        $history->execute(['id' => $versionId]);
        self::assertSame(CourseVersionStatus::PUBLISHED, $history->fetchColumn());

        $lockedEdit = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId, $boot, [
            'title' => 'Should not save',
            'description' => 'Clinician-facing overview of metabolic health.',
            'learning_objectives' => 'Apply metabolic assessment in practice.',
            'intended_audience' => 'Doctors and allied health professionals.',
            'syllabus_summary' => 'Eight-week structured syllabus.',
            'delivery_type' => 'online',
            'duration_text' => '8 weeks',
            'validity_period_days' => '365',
            'standard_fee' => '12000.00',
            'gst_rate' => '18.00',
            'currency' => 'INR',
            'certificate_type' => 'Certificate of Completion',
        ]);
        self::assertSame(409, $lockedEdit->getStatusCode());

        $batchForm = $this->request('GET', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/batches/new', $boot);
        self::assertSame(200, $batchForm->getStatusCode());
        self::assertStringContainsString('Create batch', (string) $batchForm->getBody());

        $batchCode = 'BATCH-' . strtoupper($suffix);
        $batchName = 'Open cohort ' . $suffix;
        $createBatch = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/batches',
            $boot,
            [
                'batch_code' => $batchCode,
                'name' => $batchName,
                'starts_at' => '2030-03-01T09:00',
                'ends_at' => '2030-06-01T18:00',
                'applications_open_at' => '2020-01-01T09:00',
                'applications_close_at' => '2030-02-15T18:00',
                'min_capacity' => '1',
                'max_capacity' => '40',
                'delivery_mode' => 'online',
                'venue_or_online_details' => 'Live online sessions.',
                'timezone' => 'Asia/Kolkata',
                'currency' => 'INR',
                'fee_override' => '',
            ],
        );
        self::assertSame(303, $createBatch->getStatusCode());
        self::assertStringContainsString('batch_created=', $createBatch->getHeaderLine('Location'));

        $batchStatus = $pdo->prepare('SELECT status FROM batches WHERE batch_code = :code');
        $batchStatus->execute(['code' => $batchCode]);
        self::assertSame(BatchStatus::OPEN_FOR_APPLICATIONS, $batchStatus->fetchColumn());

        $catalogue = $this->request('GET', '/courses/' . $slug, ['session' => '', 'csrf' => '']);
        self::assertSame(200, $catalogue->getStatusCode());
        self::assertStringContainsString('Publish Demo', (string) $catalogue->getBody());

        $batchesPage = $this->request('GET', '/courses/' . $slug . '/batches', ['session' => '', 'csrf' => '']);
        self::assertSame(200, $batchesPage->getStatusCode());
        $batchesHtml = (string) $batchesPage->getBody();
        self::assertStringContainsString($batchName, $batchesHtml);
        self::assertStringContainsString('Log in to apply', $batchesHtml);

        $clone = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/clone', $boot);
        self::assertSame(303, $clone->getStatusCode());
        preg_match('#^/admin/courses/\d+/versions/(\d+)\?cloned=1$#', $clone->getHeaderLine('Location'), $cm);
        $clonedId = (int) $cm[1];
        self::assertNotSame($versionId, $clonedId);

        $cloned = $pdo->prepare(
            'SELECT status, locked_at, cloned_from_version_id, version_number FROM course_versions WHERE version_id = :id',
        );
        $cloned->execute(['id' => $clonedId]);
        $clonedRow = $cloned->fetch();
        self::assertSame(CourseVersionStatus::DRAFT, $clonedRow['status']);
        self::assertNull($clonedRow['locked_at']);
        self::assertSame($versionId, (int) $clonedRow['cloned_from_version_id']);
        self::assertSame(2, (int) $clonedRow['version_number']);

        $clonedModules = (int) $pdo->query(
            'SELECT COUNT(*) FROM modules WHERE course_version_id = ' . $clonedId,
        )->fetchColumn();
        self::assertSame(1, $clonedModules);
    }

    public function testLearnerCannotPublish(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $bootAdmin = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $suffix = bin2hex(random_bytes(2));
        $create = $this->request('POST', '/admin/courses', $bootAdmin, [
            'course_code' => 'DENY-' . strtoupper($suffix),
            'slug' => 'deny-' . $suffix,
            'master_title' => 'Deny publish ' . $suffix,
        ]);
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);

        $learner = DatabaseTestCase::applicantFixture();
        $bootLearner = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request(
            'POST',
            '/admin/courses/' . $m[1] . '/versions/' . $m[2] . '/publish',
            $bootLearner,
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testCourseAdminHasPublishCloneBatchPermissions(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN);
        self::assertContains('course.version.publish', $keys);
        self::assertContains('course.version.clone', $keys);
        self::assertContains('batch.create', $keys);
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $boot, array $body = []): ResponseInterface
    {
        $cookies = [];
        if ($boot['session'] !== '') {
            $cookies[$this->sessionCookieName] = $boot['session'];
            $cookies[$this->csrfCookieName] = $boot['csrf'];
        }
        $request = (new ServerRequest([], [], 'http://localhost' . $path, $method))
            ->withCookieParams($cookies);
        if ($method !== 'GET') {
            $request = $request->withParsedBody($body + ['_csrf' => $boot['csrf'] ?? '']);
        }

        return ApplicationFactory::handle($request);
    }
}
