<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CourseCurriculumHttpTest extends TestCase
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

    public function testCourseAdminCanBuildDraftCurriculumHierarchy(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $suffix = bin2hex(random_bytes(3));
        $create = $this->request('POST', '/admin/courses', $boot, [
            'course_code' => 'CUR-' . strtoupper($suffix),
            'slug' => 'curriculum-' . $suffix,
            'master_title' => 'Certificate Course in Obesity and Metabolic Health ' . $suffix,
        ]);
        self::assertSame(303, $create->getStatusCode());
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);
        $courseId = (int) $m[1];
        $versionId = (int) $m[2];
        $curriculumPath = '/admin/courses/' . $courseId . '/versions/' . $versionId . '/curriculum';

        $page = $this->request('GET', $curriculumPath, $boot);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Curriculum', (string) $page->getBody());

        $m1 = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules', $boot, [
            'title' => 'Module 1',
            'description' => 'Foundations',
            'release_rule' => 'immediate',
            'mandatory_flag' => '1',
        ]);
        self::assertSame(303, $m1->getStatusCode());

        $m2 = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules', $boot, [
            'title' => 'Module 2',
            'description' => 'Clinical',
            'release_rule' => 'sequential',
            'mandatory_flag' => '1',
        ]);
        self::assertSame(303, $m2->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $moduleRows = $pdo->prepare('SELECT module_id, title, sequence FROM modules WHERE course_version_id = :v ORDER BY sequence');
        $moduleRows->execute(['v' => $versionId]);
        $modules = $moduleRows->fetchAll();
        self::assertCount(2, $modules);
        $module1Id = (int) $modules[0]['module_id'];
        $module2Id = (int) $modules[1]['module_id'];

        $c1 = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $module1Id . '/content',
            $boot,
            [
                'content_type' => 'text_lesson',
                'title' => 'Introduction to Metabolic Health',
                'body_text' => 'Metabolic health overview for clinicians.',
            ],
        );
        self::assertSame(303, $c1->getStatusCode());

        $c2 = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $module1Id . '/content',
            $boot,
            [
                'content_type' => 'text_lesson',
                'title' => 'Understanding Obesity',
                'body_text' => 'Obesity definitions and phenotypes.',
            ],
        );
        self::assertSame(303, $c2->getStatusCode());

        $c3 = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $module2Id . '/content',
            $boot,
            [
                'content_type' => 'text_lesson',
                'title' => 'Clinical Assessment',
                'body_text' => 'Assessment approach in practice.',
            ],
        );
        self::assertSame(303, $c3->getStatusCode());

        $outline = $this->request('GET', $curriculumPath, $boot);
        $html = (string) $outline->getBody();
        self::assertSame(200, $outline->getStatusCode());
        self::assertStringContainsString('Introduction to Metabolic Health', $html);
        self::assertStringContainsString('Understanding Obesity', $html);
        self::assertStringContainsString('Clinical Assessment', $html);
        self::assertStringContainsString('Module 1', $html);
        self::assertStringContainsString('Module 2', $html);
    }

    public function testCurriculumMutationBlockedOnLockedVersion(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $pdo = DatabaseTestCase::pdo();
        $now = gmdate('Y-m-d H:i:s.u');
        $pdo->prepare(
            'INSERT INTO course_admin_scope_assignments (
                admin_user_id, scope_type, course_id, course_version_id, include_future_versions,
                effective_from, effective_to, created_by_user_id, created_at, updated_at
             ) VALUES (
                :admin, :type, :course_id, NULL, 1, :from, NULL, :admin2, :created, :updated
             )',
        )->execute([
            'admin' => $admin['user_id'],
            'type' => 'course',
            'course_id' => $seeded['course_id'],
            'from' => $now,
            'admin2' => $admin['user_id'],
            'created' => $now,
            'updated' => $now,
        ]);

        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request(
            'POST',
            '/admin/courses/' . $seeded['course_id'] . '/versions/' . $seeded['version_id'] . '/modules',
            $boot,
            [
                'title' => 'Should fail',
                'description' => 'locked',
                'release_rule' => 'immediate',
                'mandatory_flag' => '1',
            ],
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('immutable', (string) $response->getBody());
    }

    public function testLearnerForbiddenOnCurriculum(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $bootAdmin = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $suffix = bin2hex(random_bytes(2));
        $create = $this->request('POST', '/admin/courses', $bootAdmin, [
            'course_code' => 'L3-' . strtoupper($suffix),
            'slug' => 'l3-' . $suffix,
            'master_title' => 'L3 Forbidden ' . $suffix,
        ]);
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);

        $learner = DatabaseTestCase::applicantFixture();
        $bootLearner = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request(
            'GET',
            '/admin/courses/' . $m[1] . '/versions/' . $m[2] . '/curriculum',
            $bootLearner,
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testCourseAdminHasModuleAndContentPermissions(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN);
        self::assertContains('module.manage', $keys);
        self::assertContains('content.manage', $keys);
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $boot, array $body = []): ResponseInterface
    {
        $request = (new ServerRequest([], [], 'http://localhost' . $path, $method))
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);
        if ($method !== 'GET') {
            $request = $request->withParsedBody($body + ['_csrf' => $boot['csrf']]);
        }

        return ApplicationFactory::handle($request);
    }
}
