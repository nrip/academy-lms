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

final class CertificateHttpTest extends TestCase
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

    public function testDoesNotIssueBeforeMandatoryComplete(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::setLearnerCertificateName($learner['user_id']);
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Lesson A', 'Lesson B']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );

        $list = $this->request('GET', '/learning/enrolments/' . $enrolment['enrolment_id'] . '/certificates', $boot);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('No certificates issued yet', (string) $list->getBody());

        $pdo = DatabaseTestCase::pdo();
        $count = (int) $pdo->query(
            'SELECT COUNT(*) FROM certificates WHERE enrolment_id = ' . $enrolment['enrolment_id'],
        )->fetchColumn();
        self::assertSame(0, $count);
    }

    public function testIssuesOnCompletionIsIdempotentAndPublicVerifyWorks(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::setLearnerCertificateName($learner['user_id'], 'Dr Demo Cert');
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Only lesson']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $enrolmentId = $enrolment['enrolment_id'];
        $contentId = $course['content_ids'][0];

        $complete = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId . '/complete',
            $boot,
        );
        self::assertSame(303, $complete->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $certs = $pdo->query(
            'SELECT certificate_id, certificate_number, learner_name_snapshot, current_marker
             FROM certificates WHERE enrolment_id = ' . $enrolmentId,
        )->fetchAll();
        self::assertCount(1, $certs);
        self::assertSame('Dr Demo Cert', $certs[0]['learner_name_snapshot']);
        self::assertSame(1, (int) $certs[0]['current_marker']);
        $certificateId = (int) $certs[0]['certificate_id'];
        $number = (string) $certs[0]['certificate_number'];

        // Idempotent re-trigger via list page.
        $list = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/certificates', $boot);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString($number, (string) $list->getBody());
        $count = (int) $pdo->query(
            'SELECT COUNT(*) FROM certificates WHERE enrolment_id = ' . $enrolmentId,
        )->fetchColumn();
        self::assertSame(1, $count);

        $show = $this->request('GET', '/certificates/' . $certificateId, $boot);
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('Dr Demo Cert', (string) $show->getBody());
        self::assertStringContainsString('Download PDF', (string) $show->getBody());
        self::assertStringContainsString('data-acad-print', (string) $show->getBody());
        self::assertStringNotContainsString('onclick=', (string) $show->getBody());

        $pdf = $this->request('GET', '/certificates/' . $certificateId . '/pdf', $boot);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $pdfBody = (string) $pdf->getBody();
        self::assertStringStartsWith('%PDF', $pdfBody);
        self::assertStringContainsString("4 0 obj\n<< /Length ", $pdfBody);
        self::assertStringNotContainsString('4 0 obj\\n', $pdfBody);
        self::assertStringContainsString('Dr Demo Cert', $pdfBody);

        $verify = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify/certificates/' . rawurlencode($number), 'GET'))
                ->withCookieParams([]),
        );
        self::assertSame(200, $verify->getStatusCode());
        $verifyHtml = (string) $verify->getBody();
        self::assertStringContainsString('Valid certificate', $verifyHtml);
        self::assertStringContainsString('Dr Demo Cert', $verifyHtml);
        self::assertStringNotContainsString('@', $verifyHtml);
        self::assertStringNotContainsString('phone', strtolower($verifyHtml));
    }

    public function testCannotViewAnotherLearnersCertificate(): void
    {
        $owner = DatabaseTestCase::applicantFixture();
        $other = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::setLearnerCertificateName($owner['user_id']);
        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Solo']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $owner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $bootOwner = DatabaseTestCase::bindSessionForUser($owner['user_id'], $owner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $this->request(
            'POST',
            '/learning/enrolments/' . $enrolment['enrolment_id'] . '/items/' . $course['content_ids'][0] . '/complete',
            $bootOwner,
        );
        $certificateId = (int) DatabaseTestCase::pdo()->query(
            'SELECT certificate_id FROM certificates WHERE enrolment_id = ' . $enrolment['enrolment_id'],
        )->fetchColumn();

        $bootOther = DatabaseTestCase::bindSessionForUser($other['user_id'], $other['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request('GET', '/certificates/' . $certificateId, $bootOther);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testMcqPassIssuesCertificateWhenAllMandatoryComplete(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::setLearnerCertificateName($learner['user_id'], null, 'Pat', 'Learner');
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
        $aq = DatabaseTestCase::pdo()->query(
            'SELECT attempt_question_id, correct_option_ids_json FROM assessment_attempt_questions
             WHERE attempt_id = ' . $attemptId,
        )->fetch();
        $correct = json_decode((string) $aq['correct_option_ids_json'], true, 512, JSON_THROW_ON_ERROR);

        $submit = $this->request('POST', '/learning/attempts/' . $attemptId . '/submit', $boot, [
            'answers' => [(string) $aq['attempt_question_id'] => (string) $correct[0]],
        ]);
        self::assertSame(303, $submit->getStatusCode());

        $progress = DatabaseTestCase::pdo()->prepare(
            'SELECT completion_status, completion_source FROM content_progress
             WHERE enrolment_id = :e AND content_id = :c',
        );
        $progress->execute(['e' => $enrolment['enrolment_id'], 'c' => $course['content_id']]);
        $row = $progress->fetch();
        self::assertSame(ContentProgressCompletionStatus::COMPLETED, $row['completion_status']);
        self::assertSame(ContentProgressCompletionSource::ASSESSMENT, $row['completion_source']);

        $cert = DatabaseTestCase::pdo()->query(
            'SELECT learner_name_snapshot FROM certificates WHERE enrolment_id = ' . $enrolment['enrolment_id'],
        )->fetch();
        self::assertNotFalse($cert);
        self::assertSame('Pat Learner', $cert['learner_name_snapshot']);
    }

    public function testApplicantHasCertificatePermission(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('certificate.view_own', $repo->permissionKeysForRoleKey(RoleKeys::APPLICANT));
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $boot, array $body = []): ResponseInterface
    {
        $cookies = [];
        if ($boot['session'] !== '') {
            $cookies[$this->sessionCookieName] = $boot['session'];
        }
        if ($boot['csrf'] !== '') {
            $cookies[$this->csrfCookieName] = $boot['csrf'];
        }
        $request = (new ServerRequest([], [], 'http://localhost' . $path, $method))
            ->withCookieParams($cookies);
        if ($method !== 'GET') {
            $request = $request->withParsedBody($body + ['_csrf' => $boot['csrf']]);
        }

        return ApplicationFactory::handle($request);
    }
}
