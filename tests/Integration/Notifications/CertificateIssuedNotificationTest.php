<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Notifications;

use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Notifications\NotificationDeliveryStatus;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use Academy\Infrastructure\Notifications\RecordingEmailAdapter;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class CertificateIssuedNotificationTest extends TestCase
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
        putenv('NOTIFICATION_EMAIL_ADAPTER=recording');
        $_ENV['NOTIFICATION_EMAIL_ADAPTER'] = 'recording';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    protected function tearDown(): void
    {
        putenv('NOTIFICATION_EMAIL_ADAPTER');
        unset($_ENV['NOTIFICATION_EMAIL_ADAPTER'], $_SERVER['NOTIFICATION_EMAIL_ADAPTER']);
        parent::tearDown();
    }

    public function testCertificateIssueEnqueuesAndDeliversEmailWithLink(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::setLearnerCertificateName($learner['user_id'], 'Dr Cert Mail');
        $boot = DatabaseTestCase::bindSessionForUser(
            $learner['user_id'],
            $learner['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Only lesson']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/learning/enrolments/'
                . $enrolment['enrolment_id'] . '/items/' . $course['content_ids'][0] . '/complete', 'POST'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ])
                ->withParsedBody(['_csrf' => $boot['csrf']])
                ->withHeader('X-CSRF-Token', $boot['csrf']),
        );
        self::assertSame(303, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $certificateId = (int) $pdo->query(
            'SELECT certificate_id FROM certificates WHERE enrolment_id = ' . (int) $enrolment['enrolment_id'],
        )->fetchColumn();
        self::assertGreaterThan(0, $certificateId);

        $courseTitle = (string) $pdo->query(
            'SELECT master_title FROM courses WHERE course_id = ' . (int) $course['course_id'],
        )->fetchColumn();

        $outbox = $pdo->query(
            "SELECT event_type, idempotency_key, payload FROM outbox_messages
             WHERE event_type = 'certificate.issued' ORDER BY outbox_message_id DESC LIMIT 1",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($outbox);
        self::assertSame(TransactionalNotificationEventTypes::CERTIFICATE_ISSUED, $outbox['event_type']);
        self::assertSame('certificate.issued:' . $certificateId, $outbox['idempotency_key']);

        $container = ApplicationFactory::container('testing');
        /** @var RecordingEmailAdapter $recording */
        $recording = $container->get(RecordingEmailAdapter::class);

        $processed = $container->get(TransactionalNotificationDeliveryWorker::class)->run('cert-mail', 20);
        self::assertGreaterThanOrEqual(1, $processed);

        $certMails = array_values(array_filter(
            $recording->recorded(),
            static fn ($m): bool => $m->templateKey === 'certificate_issued',
        ));
        self::assertNotEmpty($certMails);
        $mail = $certMails[array_key_last($certMails)];
        self::assertStringContainsString('Dr Cert Mail', $mail->bodyText);
        self::assertStringContainsString($courseTitle, $mail->bodyText);
        self::assertStringContainsString('/certificates/' . $certificateId, $mail->bodyText);

        $deliveryStatus = $pdo->query(
            "SELECT status FROM notification_deliveries
             WHERE source_event_type = 'certificate.issued' ORDER BY notification_delivery_id DESC LIMIT 1",
        )->fetchColumn();
        self::assertSame(NotificationDeliveryStatus::DELIVERED, $deliveryStatus);
    }
}
