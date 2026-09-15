<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CourseAdmissionConfigHttpTest extends TestCase
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

    public function testCourseAdminConfiguresEligibilityAndDocumentsForPublicPageAndApplication(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $suffix = bin2hex(random_bytes(3));
        $seeded = DatabaseTestCase::seedPublishedCourse([
            'locked' => false,
            'slug' => 'admission-config-' . $suffix,
            'master_title' => 'Admission config ' . $suffix,
        ]);
        $this->assignScope($admin['user_id'], $seeded['course_id']);
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $path = '/admin/courses/' . $seeded['course_id'] . '/versions/' . $seeded['version_id'] . '/admission';
        $show = $this->request('GET', $path, $boot);
        self::assertSame(200, $show->getStatusCode());
        $html = (string) $show->getBody();
        self::assertStringContainsString('Eligible learner categories', $html);
        self::assertStringContainsString('Add a document', $html);
        self::assertStringNotContainsString('allied_professional', $html);
        self::assertStringNotContainsString('eligibility_rules', $html);
        self::assertStringNotContainsString('course_document_requirements', $html);

        $note = 'Must hold a current council registration ' . $suffix;
        $save = $this->request('POST', $path . '/eligibility', $boot, [
            'categories' => ['Doctor', 'Nurse'],
            'eligibility_notes' => $note,
        ]);
        self::assertSame(303, $save->getStatusCode());

        $documentName = 'Council registration scan ' . $suffix;
        $description = 'A clear photo of the current registration certificate.';
        $add = $this->request('POST', $path . '/documents', $boot, [
            'name' => $documentName,
            'description' => $description,
            'mandatory' => '1',
            'display_order' => '1',
        ]);
        self::assertSame(303, $add->getStatusCode());

        $configured = (string) $this->request('GET', $path, $boot)->getBody();
        self::assertStringContainsString($documentName, $configured);
        self::assertStringContainsString($note, $configured);
        self::assertStringNotContainsString('allied_professional', $configured);

        DatabaseTestCase::lockCourseVersion($seeded['version_id']);
        $public = $this->request('GET', '/courses/admission-config-' . $suffix, $boot);
        self::assertSame(200, $public->getStatusCode());
        $publicHtml = (string) $public->getBody();
        self::assertStringContainsString('Doctor or Nurse', $publicHtml);
        self::assertStringContainsString($note, $publicHtml);
        self::assertStringContainsString($documentName, $publicHtml);
        self::assertStringContainsString($description, $publicHtml);
        self::assertStringNotContainsString('allied_professional', $publicHtml);

        $locked = $this->request('POST', $path . '/eligibility', $boot, [
            'categories' => ['Doctor'],
            'eligibility_notes' => 'Should not save',
        ]);
        self::assertSame(409, $locked->getStatusCode());
        self::assertStringContainsString('next edition', (string) $locked->getBody());
        self::assertStringNotContainsString('Should not save', (string) $this->request('GET', '/courses/admission-config-' . $suffix, $boot)->getBody());

        $requirementId = $this->requirementId($seeded['version_id'], $documentName);
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id']);
        $fixture = ReviewerTestFixture::seedUnderReviewApplication([], [
            'catalogue' => [
                'course_id' => $seeded['course_id'],
                'version_id' => $seeded['version_id'],
                'batch_id' => $batchId,
                'requirement_ids' => [$requirementId],
            ],
        ]);

        $documents = $this->request('GET', '/applications/' . $fixture['application_id'] . '/documents', $fixture['applicant_session']);
        self::assertSame(200, $documents->getStatusCode());
        self::assertStringContainsString($documentName, (string) $documents->getBody());
        self::assertStringContainsString($description, (string) $documents->getBody());

        $reviewer = $this->request('GET', '/reviewer/applications/' . $fixture['application_id'], $fixture['reviewer_session']);
        self::assertSame(200, $reviewer->getStatusCode());
        self::assertStringContainsString($documentName, (string) $reviewer->getBody());

        $claim = $this->request('POST', '/reviewer/applications/' . $fixture['application_id'] . '/claim', $fixture['reviewer_session'], [], true);
        self::assertSame(200, $claim->getStatusCode());
        $verify = $this->request(
            'POST',
            '/reviewer/applications/' . $fixture['application_id'] . '/documents/' . $fixture['submission_ids'][0] . '/verify',
            $fixture['reviewer_session'],
            [],
            true,
        );
        self::assertSame(200, $verify->getStatusCode());
    }

    public function testLearnerAndFinanceCannotOpenAdmissionConfiguration(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['locked' => false]);
        $path = '/admin/courses/' . $seeded['course_id'] . '/versions/' . $seeded['version_id'] . '/admission';

        foreach ([
            DatabaseTestCase::applicantFixture(),
            DatabaseTestCase::financeFixture(),
        ] as $user) {
            $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
            self::assertSame(403, $this->request('GET', $path, $boot)->getStatusCode());
        }
    }

    public function testMissingDocumentNameIsRejected(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $seeded = DatabaseTestCase::seedPublishedCourse(['locked' => false]);
        $this->assignScope($admin['user_id'], $seeded['course_id']);
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $response = $this->request(
            'POST',
            '/admin/courses/' . $seeded['course_id'] . '/versions/' . $seeded['version_id'] . '/admission/documents',
            $boot,
            ['name' => '   ', 'description' => 'Missing name', 'display_order' => '1'],
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter a document name.', (string) $response->getBody());
    }

    private function assignScope(int $adminUserId, int $courseId): void
    {
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
            'admin' => $adminUserId,
            'type' => 'course',
            'course_id' => $courseId,
            'from' => $now,
            'admin2' => $adminUserId,
            'created' => $now,
            'updated' => $now,
        ]);
    }

    private function requirementId(int $versionId, string $name): int
    {
        $stmt = DatabaseTestCase::pdo()->prepare(
            'SELECT requirement_id FROM course_document_requirements
             WHERE course_version_id = :version_id AND document_name = :name',
        );
        $stmt->execute(['version_id' => $versionId, 'name' => $name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $boot, array $body = [], bool $json = false): ResponseInterface
    {
        $request = (new ServerRequest([], [], 'http://localhost' . $path, $method))
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);
        if ($json) {
            $request = $request->withHeader('Accept', 'application/json')->withHeader('X-CSRF-Token', $boot['csrf']);
        }
        if ($method !== 'GET') {
            $request = $request->withParsedBody($body + ['_csrf' => $boot['csrf']]);
        }

        return ApplicationFactory::handle($request);
    }
}
