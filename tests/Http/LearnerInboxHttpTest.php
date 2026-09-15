<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LearnerInboxHttpTest extends TestCase
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

    public function testLearnerSeesOwnUpdatesAndCannotReadAnotherLearners(): void
    {
        $owner = PaymentTestFixture::seedPaymentPendingApplication();
        $intruder = PaymentTestFixture::seedPaymentPendingApplication();
        ApplicationFactory::container('testing')
            ->get(TransactionalNotificationDeliveryWorker::class)
            ->run('inbox-http', 20);

        $ownerReference = $this->applicationReferenceFor($owner['applicant_user_id']);
        $intruderReference = $this->applicationReferenceFor($intruder['applicant_user_id']);
        self::assertNotSame($ownerReference, $intruderReference);

        $intruderPage = $this->get('/notifications', $intruder['applicant_session']);
        self::assertSame(200, $intruderPage->getStatusCode());
        $intruderBody = (string) $intruderPage->getBody();
        self::assertStringContainsString($intruderReference, $intruderBody);
        self::assertStringNotContainsString($ownerReference, $intruderBody);
        self::assertStringNotContainsString('learning/catalogue/', $intruderBody);
        self::assertStringNotContainsString('learning/media/', $intruderBody);

        $ownerPage = $this->get('/notifications', $owner['applicant_session']);
        self::assertSame(200, $ownerPage->getStatusCode());
        self::assertStringContainsString($ownerReference, (string) $ownerPage->getBody());

        $ownerIds = $this->idsFor($owner['applicant_user_id']);
        self::assertNotEmpty($ownerIds);
        $denied = $this->post('/notifications/' . $ownerIds[0] . '/read', $intruder['applicant_session']);
        self::assertSame(404, $denied->getStatusCode());

        foreach ($ownerIds as $ownerId) {
            $read = $this->post('/notifications/' . $ownerId . '/read', $owner['applicant_session']);
            self::assertSame(303, $read->getStatusCode());
        }

        $after = $this->get('/notifications', $owner['applicant_session']);
        self::assertStringContainsString('No unread updates.', (string) $after->getBody());
    }

    private function applicationReferenceFor(int $userId): string
    {
        $stmt = DatabaseTestCase::pdo()->prepare(
            'SELECT body FROM in_app_notifications WHERE user_id = ? ORDER BY in_app_notification_id ASC LIMIT 1',
        );
        $stmt->execute([$userId]);
        $body = (string) $stmt->fetchColumn();
        if (preg_match('/Application reference: (\S+)\./', $body, $match) !== 1) {
            self::fail('Inbox body is missing the application reference.');
        }

        return $match[1];
    }

    /**
     * @return list<int>
     */
    private function idsFor(int $userId): array
    {
        $stmt = DatabaseTestCase::pdo()->prepare(
            'SELECT in_app_notification_id FROM in_app_notifications WHERE user_id = ? ORDER BY in_app_notification_id ASC',
        );
        $stmt->execute([$userId]);

        return array_map(static fn (mixed $id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array{session: string, csrf: string} $boot
     */
    private function get(string $path, array $boot): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }

    /**
     * @param array{session: string, csrf: string} $boot
     */
    private function post(string $path, array $boot): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'POST'))
                ->withParsedBody(['_csrf' => $boot['csrf']])
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
