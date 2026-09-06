<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Learning\ContentProgressCompletionSource;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class AssessmentAttemptHttpTest extends TestCase
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
        DatabaseTestCase::truncateAllTestTables();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testStartAnswerSubmitPassMarksContentComplete(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::setLearnerCertificateName($learner['user_id']);
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $course = DatabaseTestCase::seedPublishedCourseWithMcqAssessment([
            'question_count' => 2,
            'questions_per_attempt' => 2,
            'pass_threshold_percent' => '50.00',
        ]);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $enrolmentId = $enrolment['enrolment_id'];
        $assessmentId = $course['assessment_id'];

        $item = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $course['content_id'], $boot);
        self::assertSame(200, $item->getStatusCode());
        self::assertStringContainsString('Start attempt', (string) $item->getBody());

        $start = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/assessments/' . $assessmentId . '/attempts',
            $boot,
        );
        self::assertSame(303, $start->getStatusCode());
        $location = $start->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/learning/attempts/(\d+)$#', $location);
        preg_match('#^/learning/attempts/(\d+)$#', $location, $m);
        $attemptId = (int) $m[1];

        $show = $this->request('GET', '/learning/attempts/' . $attemptId, $boot);
        self::assertSame(200, $show->getStatusCode());
        $html = (string) $show->getBody();
        self::assertStringContainsString('Fixture question 1?', $html);
        self::assertStringNotContainsString('badge text-bg-success', $html);

        $pdo = DatabaseTestCase::pdo();
        $aqRows = $pdo->query(
            'SELECT attempt_question_id, question_id, correct_option_ids_json
             FROM assessment_attempt_questions WHERE attempt_id = ' . $attemptId . ' ORDER BY sequence',
        )->fetchAll();
        self::assertCount(2, $aqRows);

        $answers = [];
        foreach ($aqRows as $row) {
            /** @var list<int> $correctIds */
            $correctIds = json_decode((string) $row['correct_option_ids_json'], true, 512, JSON_THROW_ON_ERROR);
            $answers[(string) $row['attempt_question_id']] = (string) $correctIds[0];
        }

        $submit = $this->request('POST', '/learning/attempts/' . $attemptId . '/submit', $boot, [
            'answers' => $answers,
        ]);
        self::assertSame(303, $submit->getStatusCode());

        $result = $this->request('GET', '/learning/attempts/' . $attemptId, $boot);
        $resultHtml = (string) $result->getBody();
        self::assertStringContainsString('Passed.', $resultHtml);
        self::assertStringContainsString('Score:', $resultHtml);
        self::assertStringContainsString('View certificate', $resultHtml);
        self::assertStringContainsString('completion certificate is ready', $resultHtml);

        $progress = $pdo->prepare(
            'SELECT completion_status, completion_source FROM content_progress
             WHERE enrolment_id = :e AND content_id = :c',
        );
        $progress->execute(['e' => $enrolmentId, 'c' => $course['content_id']]);
        $progressRow = $progress->fetch();
        self::assertNotFalse($progressRow);
        self::assertSame(ContentProgressCompletionStatus::COMPLETED, $progressRow['completion_status']);
        self::assertSame(ContentProgressCompletionSource::ASSESSMENT, $progressRow['completion_source']);
    }

    public function testSecondInProgressStartResumesExistingAttempt(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $course = DatabaseTestCase::seedPublishedCourseWithMcqAssessment();
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );

        $first = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolment['enrolment_id']
            . '/assessments/' . $course['assessment_id'] . '/attempts',
            $boot,
        );
        self::assertSame(303, $first->getStatusCode());
        $firstLocation = $first->getHeaderLine('Location');

        $second = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolment['enrolment_id']
            . '/assessments/' . $course['assessment_id'] . '/attempts',
            $boot,
        );
        self::assertSame(303, $second->getStatusCode());
        self::assertSame($firstLocation, $second->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $count = (int) $pdo->query(
            'SELECT COUNT(*) FROM assessment_attempts WHERE assessment_id = ' . $course['assessment_id']
            . ' AND enrolment_id = ' . $enrolment['enrolment_id'],
        )->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testCannotOpenAnotherLearnersAttempt(): void
    {
        $owner = DatabaseTestCase::applicantFixture();
        $other = DatabaseTestCase::applicantFixture();
        $course = DatabaseTestCase::seedPublishedCourseWithMcqAssessment();
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $owner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $bootOwner = DatabaseTestCase::bindSessionForUser($owner['user_id'], $owner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $start = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolment['enrolment_id']
            . '/assessments/' . $course['assessment_id'] . '/attempts',
            $bootOwner,
        );
        preg_match('#^/learning/attempts/(\d+)$#', $start->getHeaderLine('Location'), $m);
        $attemptId = (int) $m[1];

        $bootOther = DatabaseTestCase::bindSessionForUser($other['user_id'], $other['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request('GET', '/learning/attempts/' . $attemptId, $bootOther);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testScoringUsesSnapshotAfterBankEdit(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $course = DatabaseTestCase::seedPublishedCourseWithMcqAssessment([
            'question_count' => 1,
            'questions_per_attempt' => 1,
            'pass_threshold_percent' => '100.00',
        ]);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );

        $start = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolment['enrolment_id']
            . '/assessments/' . $course['assessment_id'] . '/attempts',
            $boot,
        );
        preg_match('#^/learning/attempts/(\d+)$#', $start->getHeaderLine('Location'), $m);
        $attemptId = (int) $m[1];

        $pdo = DatabaseTestCase::pdo();
        $aq = $pdo->query(
            'SELECT attempt_question_id, question_id, correct_option_ids_json
             FROM assessment_attempt_questions WHERE attempt_id = ' . $attemptId,
        )->fetch();
        self::assertNotFalse($aq);
        /** @var list<int> $snapshotCorrect */
        $snapshotCorrect = json_decode((string) $aq['correct_option_ids_json'], true, 512, JSON_THROW_ON_ERROR);
        $snapshotCorrectId = (int) $snapshotCorrect[0];

        // Flip live bank correct answer after snapshot was taken.
        $pdo->exec('UPDATE question_options SET is_correct = 0 WHERE question_id = ' . (int) $aq['question_id']);
        $pdo->exec(
            'UPDATE question_options SET is_correct = 1
             WHERE question_id = ' . (int) $aq['question_id']
            . ' AND option_id <> ' . $snapshotCorrectId,
        );

        $submit = $this->request('POST', '/learning/attempts/' . $attemptId . '/submit', $boot, [
            'answers' => [(string) $aq['attempt_question_id'] => (string) $snapshotCorrectId],
        ]);
        self::assertSame(303, $submit->getStatusCode());

        $attempt = $pdo->query(
            'SELECT score_percent, passed_flag FROM assessment_attempts WHERE attempt_id = ' . $attemptId,
        )->fetch();
        self::assertSame('100.00', $attempt['score_percent']);
        self::assertSame(1, (int) $attempt['passed_flag']);
    }

    public function testApplicantHasAttemptPermission(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('assessment.attempt.own', $repo->permissionKeysForRoleKey(RoleKeys::APPLICANT));
        self::assertNotContains('assessment.attempt.own', $repo->permissionKeysForRoleKey(RoleKeys::FINANCE_ADMIN));
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
