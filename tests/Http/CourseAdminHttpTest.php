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

final class CourseAdminHttpTest extends TestCase
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

    public function testCourseAdminCanCreateAndEditDraft(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $suffix = bin2hex(random_bytes(3));
        $create = $this->request('POST', '/admin/courses', $boot, [
            'course_code' => 'CA-' . strtoupper($suffix),
            'slug' => 'ca-demo-' . $suffix,
            'master_title' => 'Course Admin Demo ' . $suffix,
        ]);
        self::assertSame(303, $create->getStatusCode());
        $location = $create->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/admin/courses/\d+/versions/\d+$#', $location);

        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $location, $m);
        $courseId = (int) $m[1];
        $versionId = (int) $m[2];

        $show = $this->request('GET', $location, $boot);
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('Draft (editable)', (string) $show->getBody());

        $update = $this->request('POST', '/admin/courses/' . $courseId . '/versions/' . $versionId, $boot, [
            'title' => 'Updated title ' . $suffix,
            'description' => 'Updated description for demo course.',
            'learning_objectives' => 'Updated objectives.',
            'intended_audience' => 'Clinicians.',
            'syllabus_summary' => 'Module overview.',
            'delivery_type' => 'online',
            'duration_text' => '4 weeks',
            'validity_period_days' => '180',
            'standard_fee' => '12000.00',
            'gst_rate' => '18.00',
            'currency' => 'INR',
            'certificate_type' => 'Certificate of Completion',
        ]);
        self::assertSame(303, $update->getStatusCode());
    }

    public function testLearnerFinanceReviewerForbiddenOnAdminCourses(): void
    {
        foreach ([
            DatabaseTestCase::applicantFixture(),
            DatabaseTestCase::financeFixture(),
            DatabaseTestCase::reviewerFixture(),
        ] as $user) {
            $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
            $response = $this->request('GET', '/admin/courses', $boot);
            self::assertSame(403, $response->getStatusCode(), 'Expected 403 for unauthorized role');
        }
    }

    public function testCourseAdminCannotEditLockedPublishedVersion(): void
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
            '/admin/courses/' . $seeded['course_id'] . '/versions/' . $seeded['version_id'],
            $boot,
            [
                'title' => 'Should fail',
                'description' => 'Should fail',
                'learning_objectives' => 'Should fail',
                'intended_audience' => 'Should fail',
                'syllabus_summary' => 'Should fail',
                'delivery_type' => 'online',
                'duration_text' => '1 week',
                'validity_period_days' => '',
                'standard_fee' => '1.00',
                'gst_rate' => '18.00',
                'currency' => 'INR',
                'certificate_type' => 'Certificate of Completion',
            ],
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('immutable', (string) $response->getBody());
    }

    public function testCourseAdminRoleLacksDocumentAndRefundPermissions(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN);
        self::assertContains('course.create', $keys);
        self::assertContains('course.version.edit', $keys);
        self::assertNotContains('document.signed_url.generate', $keys);
        self::assertNotContains('document.metadata.view', $keys);
        self::assertNotContains('finance.refund.approve', $keys);
        self::assertNotContains('reviewer.document.review', $keys);
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
