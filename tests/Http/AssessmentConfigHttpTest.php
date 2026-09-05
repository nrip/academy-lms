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

final class AssessmentConfigHttpTest extends TestCase
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

    public function testCourseAdminCanConfigureAssessmentOnMcqContent(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $suffix = bin2hex(random_bytes(3));
        $create = $this->request('POST', '/admin/courses', $boot, [
            'course_code' => 'AS-' . strtoupper($suffix),
            'slug' => 'assess-' . $suffix,
            'master_title' => 'Assessment Config ' . $suffix,
        ]);
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);
        $courseId = (int) $m[1];
        $versionId = (int) $m[2];

        $module = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules', $boot, [
            'title' => 'Module 2',
            'description' => 'Clinical',
            'release_rule' => 'immediate',
            'mandatory_flag' => '1',
        ]);
        self::assertSame(303, $module->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $moduleId = (int) $pdo->query(
            'SELECT module_id FROM modules WHERE course_version_id = ' . $versionId . ' ORDER BY module_id DESC LIMIT 1',
        )->fetchColumn();

        $content = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            [
                'content_type' => 'mcq_assessment',
                'title' => 'Clinical Assessment',
                'completion_rule' => 'assessment_passed',
            ],
        );
        self::assertSame(303, $content->getStatusCode());
        $contentId = (int) $pdo->query(
            'SELECT content_id FROM content_items WHERE module_id = ' . $moduleId . ' ORDER BY content_id DESC LIMIT 1',
        )->fetchColumn();

        $bankPath = '/admin/courses/' . $courseId . '/question-bank';
        $questionIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $q = $this->request('POST', $bankPath . '/questions', $boot, [
                'question_type' => 'mcq_single',
                'stem' => 'Clinical question ' . $i . ' for ' . $suffix . '?',
                'marks' => '1.00',
                'status' => 'active',
                'correct_option' => '0',
                'options' => [
                    ['option_text' => 'Correct'],
                    ['option_text' => 'Wrong'],
                ],
            ]);
            self::assertSame(303, $q->getStatusCode());
        }
        $rows = $pdo->query(
            'SELECT q.question_id FROM questions q
             INNER JOIN question_banks b ON b.bank_id = q.bank_id
             WHERE b.course_id = ' . $courseId . ' ORDER BY q.question_id ASC',
        )->fetchAll();
        foreach ($rows as $row) {
            $questionIds[] = (string) $row['question_id'];
        }
        self::assertCount(5, $questionIds);

        $show = $this->request('GET', '/admin/content-items/' . $contentId . '/assessment', $boot);
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('Assessment configuration', (string) $show->getBody());

        $save = $this->request('POST', '/admin/content-items/' . $contentId . '/assessment', $boot, [
            'title' => 'Clinical Assessment Quiz',
            'questions_per_attempt' => '5',
            'pass_threshold_percent' => '60.00',
            'max_attempts' => '3',
            'question_ids' => $questionIds,
        ]);
        self::assertSame(303, $save->getStatusCode());

        $view = $this->request('GET', '/admin/content-items/' . $contentId . '/assessment', $boot);
        $html = (string) $view->getBody();
        self::assertSame(200, $view->getStatusCode());
        self::assertStringContainsString('Clinical Assessment Quiz', $html);
        self::assertStringContainsString('60.00%', $html);
        self::assertStringContainsString('Configured assessment', $html);
    }

    public function testLockedVersionCannotSaveAssessment(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $suffix = bin2hex(random_bytes(2));
        $create = $this->request('POST', '/admin/courses', $boot, [
            'course_code' => 'AL-' . strtoupper($suffix),
            'slug' => 'alock-' . $suffix,
            'master_title' => 'Locked Assess ' . $suffix,
        ]);
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);
        $courseId = (int) $m[1];
        $versionId = (int) $m[2];

        $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules', $boot, [
            'title' => 'M1',
            'description' => '',
            'release_rule' => 'immediate',
            'mandatory_flag' => '1',
        ]);
        $pdo = DatabaseTestCase::pdo();
        $moduleId = (int) $pdo->query(
            'SELECT module_id FROM modules WHERE course_version_id = ' . $versionId . ' LIMIT 1',
        )->fetchColumn();
        $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            ['content_type' => 'mcq_assessment', 'title' => 'Quiz', 'completion_rule' => 'assessment_passed'],
        );
        $contentId = (int) $pdo->query(
            'SELECT content_id FROM content_items WHERE module_id = ' . $moduleId . ' LIMIT 1',
        )->fetchColumn();

        $now = gmdate('Y-m-d H:i:s.u');
        $pdo->prepare(
            "UPDATE course_versions SET status = 'published', locked_at = :locked, locked_reason = 'test'
             WHERE version_id = :id",
        )->execute(['locked' => $now, 'id' => $versionId]);

        $response = $this->request('POST', '/admin/content-items/' . $contentId . '/assessment', $boot, [
            'title' => 'Should fail',
            'questions_per_attempt' => '1',
            'pass_threshold_percent' => '60',
            'max_attempts' => '1',
            'question_ids' => ['1'],
        ]);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('immutable', (string) $response->getBody());
    }

    public function testLearnerForbiddenOnAssessmentConfig(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $bootAdmin = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $suffix = bin2hex(random_bytes(2));
        $create = $this->request('POST', '/admin/courses', $bootAdmin, [
            'course_code' => 'AF-' . strtoupper($suffix),
            'slug' => 'aforb-' . $suffix,
            'master_title' => 'Assess Forbidden ' . $suffix,
        ]);
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);
        $this->request('POST', '/admin/courses/' . $m[1] . '/versions/' . $m[2] . '/modules', $bootAdmin, [
            'title' => 'M1',
            'description' => '',
            'release_rule' => 'immediate',
            'mandatory_flag' => '1',
        ]);
        $pdo = DatabaseTestCase::pdo();
        $moduleId = (int) $pdo->query(
            'SELECT module_id FROM modules WHERE course_version_id = ' . (int) $m[2] . ' LIMIT 1',
        )->fetchColumn();
        $this->request(
            'POST',
            '/admin/courses/' . $m[1] . '/versions/' . $m[2] . '/modules/' . $moduleId . '/content',
            $bootAdmin,
            ['content_type' => 'mcq_assessment', 'title' => 'Quiz', 'completion_rule' => 'assessment_passed'],
        );
        $contentId = (int) $pdo->query(
            'SELECT content_id FROM content_items WHERE module_id = ' . $moduleId . ' LIMIT 1',
        )->fetchColumn();

        $learner = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request('GET', '/admin/content-items/' . $contentId . '/assessment', $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAssessmentManagePermissionOnCourseAdmin(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('assessment.manage', $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN));
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, mixed> $body
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
