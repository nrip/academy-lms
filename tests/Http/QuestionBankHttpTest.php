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

final class QuestionBankHttpTest extends TestCase
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

    public function testCourseAdminCanCreateEditAndListMcqQuestions(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $suffix = bin2hex(random_bytes(3));
        $create = $this->request('POST', '/admin/courses', $boot, [
            'course_code' => 'QB-' . strtoupper($suffix),
            'slug' => 'qb-' . $suffix,
            'master_title' => 'Question Bank Course ' . $suffix,
        ]);
        self::assertSame(303, $create->getStatusCode());
        preg_match('#^/admin/courses/(\d+)/versions/\d+$#', $create->getHeaderLine('Location'), $m);
        $courseId = (int) $m[1];
        $bankPath = '/admin/courses/' . $courseId . '/question-bank';

        $list = $this->request('GET', $bankPath, $boot);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('Question bank', (string) $list->getBody());
        self::assertStringContainsString('Correct answers are visible to Course Admins', (string) $list->getBody());

        $pdo = DatabaseTestCase::pdo();
        $bankId = (int) $pdo->query('SELECT bank_id FROM question_banks WHERE course_id = ' . $courseId)->fetchColumn();
        self::assertGreaterThan(0, $bankId);

        $createQ = $this->request('POST', $bankPath . '/questions', $boot, [
            'question_type' => 'mcq_single',
            'stem' => 'Which hormone is most associated with satiety?',
            'marks' => '1.00',
            'status' => 'active',
            'correct_option' => '1',
            'options' => [
                ['option_text' => 'Ghrelin'],
                ['option_text' => 'Leptin'],
                ['option_text' => 'Cortisol'],
                ['option_text' => 'Insulin'],
            ],
        ]);
        self::assertSame(303, $createQ->getStatusCode());

        $questionId = (int) $pdo->query(
            'SELECT question_id FROM questions WHERE bank_id = ' . $bankId . ' ORDER BY question_id DESC LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $questionId);

        $correct = $pdo->query(
            'SELECT option_text FROM question_options WHERE question_id = ' . $questionId . ' AND is_correct = 1',
        )->fetchColumn();
        self::assertSame('Leptin', $correct);

        $update = $this->request('POST', $bankPath . '/questions/' . $questionId, $boot, [
            'question_type' => 'mcq_single',
            'stem' => 'Which hormone is most associated with satiety? (edited)',
            'marks' => '2.00',
            'status' => 'active',
            'correct_option' => '0',
            'options' => [
                ['option_text' => 'Leptin'],
                ['option_text' => 'Ghrelin'],
                ['option_text' => 'Cortisol'],
            ],
        ]);
        self::assertSame(303, $update->getStatusCode());

        $shown = $this->request('GET', $bankPath, $boot);
        $html = (string) $shown->getBody();
        self::assertSame(200, $shown->getStatusCode());
        self::assertStringContainsString('satiety? (edited)', $html);
        self::assertStringContainsString('Correct', $html);

        $version = (int) $pdo->query(
            'SELECT version FROM questions WHERE question_id = ' . $questionId,
        )->fetchColumn();
        self::assertSame(2, $version);
    }

    public function testLearnerAndFinanceForbiddenOnQuestionBank(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $bootAdmin = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $suffix = bin2hex(random_bytes(2));
        $create = $this->request('POST', '/admin/courses', $bootAdmin, [
            'course_code' => 'QBF-' . strtoupper($suffix),
            'slug' => 'qbf-' . $suffix,
            'master_title' => 'QB Forbidden ' . $suffix,
        ]);
        preg_match('#^/admin/courses/(\d+)/#', $create->getHeaderLine('Location'), $m);
        $path = '/admin/courses/' . $m[1] . '/question-bank';

        foreach ([
            DatabaseTestCase::applicantFixture(),
            DatabaseTestCase::financeFixture(),
            DatabaseTestCase::reviewerFixture(),
        ] as $user) {
            $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
            $response = $this->request('GET', $path, $boot);
            self::assertSame(403, $response->getStatusCode());
        }
    }

    public function testCourseAdminHasQuestionBankPermission(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN);
        self::assertContains('question_bank.manage', $keys);
        self::assertNotContains('document.metadata.view', $keys);
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
