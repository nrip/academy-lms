<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\PendingVerificationAllowList;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Identity\PdoLearnerQualificationRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LearnerProfileSecurityTest extends TestCase
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

    public function testPendingUserCannotEditProfile(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'pending.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
            AccountStatus::PENDING_VERIFICATION,
        );
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
        $boot = $this->boot($user['user_id'], $user['auth_version']);

        $response = $this->post('/profile/personal', $boot, ['row_version' => '1', 'first_name' => 'Nope']);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testIdorUpdateOfAnotherUsersQualificationDenied(): void
    {
        $ownerProfileId = $this->applicantWithStub();
        $qualificationId = $this->seedQualification($ownerProfileId);

        $intruder = DatabaseTestCase::financeFixture();
        DatabaseTestCase::ensureLearnerProfileStub($intruder['user_id']);
        $boot = $this->boot($intruder['user_id'], $intruder['auth_version']);

        $response = $this->post('/profile/qualifications/' . $qualificationId . '/update', $boot, [
            'row_version' => '1',
            'qualification_type' => 'Degree',
            'qualification_name' => 'Hacked',
            'institution_name' => 'Nowhere',
            'completion_year' => '2010',
        ]);
        self::assertSame(403, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT qualification_name FROM learner_qualifications WHERE learner_qualification_id = ?');
        $stmt->execute([$qualificationId]);
        self::assertSame('MBBS', $stmt->fetchColumn(), 'IDOR update must not mutate the victim row.');
    }

    public function testMissingCsrfRejected(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
        $boot = $this->boot($user['user_id'], $user['auth_version']);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/profile/personal', 'POST'))
                ->withParsedBody(['row_version' => '1', 'first_name' => 'X'])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAuditNeverStoresSensitiveProfileValues(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
        $boot = $this->boot($user['user_id'], $user['auth_version']);

        $this->post('/profile/personal', $boot, [
            'row_version' => '1',
            'first_name' => 'SensitiveGiven',
            'address_line_1' => '221B Baker Street',
            'alternate_mobile' => '9876543210',
            'date_of_birth' => '1990-01-01',
        ]);

        $pdo = DatabaseTestCase::pdo();
        $rows = $pdo->query('SELECT new_value FROM audit_log')->fetchAll(\PDO::FETCH_COLUMN);
        $joined = implode('|', array_map(static fn ($v): string => (string) $v, $rows));

        self::assertStringNotContainsString('221B Baker Street', $joined);
        self::assertStringNotContainsString('9876543210', $joined);
        self::assertStringNotContainsString('1990-01-01', $joined);
        self::assertStringNotContainsString('SensitiveGiven', $joined);
    }

    public function testUnsupportedMassAssignmentFieldsIgnored(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
        $boot = $this->boot($user['user_id'], $user['auth_version']);

        $response = $this->post('/profile/personal', $boot, [
            'row_version' => '1',
            'first_name' => 'Asha',
            'account_status' => 'suspended',
            'user_id' => '999999',
            'learner_profile_id' => '999999',
        ]);
        self::assertSame(303, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $status = $pdo->prepare('SELECT account_status FROM users WHERE user_id = ?');
        $status->execute([$user['user_id']]);
        self::assertSame(AccountStatus::ACTIVE, $status->fetchColumn(), 'account_status must be immune to mass assignment.');
    }

    public function testNoProfileDataWrittenToSession(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
        $boot = $this->boot($user['user_id'], $user['auth_version']);

        $this->post('/profile/personal', $boot, ['row_version' => '1', 'first_name' => 'SessionLeakName']);

        $pdo = DatabaseTestCase::pdo();
        $payloads = $pdo->query('SELECT payload FROM sessions')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($payloads as $payload) {
            self::assertStringNotContainsString('SessionLeakName', (string) $payload);
            self::assertStringNotContainsString('first_name', (string) $payload);
        }
    }

    public function testPendingAllowListDoesNotIncludeProfilePermissions(): void
    {
        foreach ([
            'profile.personal.view_own',
            'profile.personal.edit_own',
            'profile.professional.view_own',
            'profile.professional.edit_own',
            'profile.view_any',
            'profile.edit_any',
        ] as $key) {
            self::assertFalse(PendingVerificationAllowList::contains($key), $key . ' must remain denied while pending.');
        }
    }

    private function applicantWithStub(): int
    {
        $owner = DatabaseTestCase::applicantFixture();

        return DatabaseTestCase::ensureLearnerProfileStub($owner['user_id']);
    }

    private function seedQualification(int $profileId): int
    {
        $repository = new PdoLearnerQualificationRepository(DatabaseTestCase::connectionFactory());

        return $repository->insert(
            $profileId,
            [
                'qualification_type' => 'Degree',
                'qualification_name' => 'MBBS',
                'institution_name' => 'AIIMS',
                'university_or_board' => null,
                'country' => null,
                'completion_year' => 2010,
                'registration_or_certificate_number' => null,
            ],
            1,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * @return array{session: string, csrf: string}
     */
    private function boot(int $userId, int $authVersion): array
    {
        $boot = DatabaseTestCase::bindSessionForUser($userId, $authVersion, AuthStage::FULLY_AUTHENTICATED);

        return ['session' => $boot['session'], 'csrf' => $boot['csrf']];
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, string> $body
     */
    private function post(string $path, array $boot, array $body): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody($body + ['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
