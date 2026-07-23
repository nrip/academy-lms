<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Identity\PasswordHasher;
use Academy\Application\Identity\PostLoginDestinationResolver;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class PostLoginRedirectHttpTest extends TestCase
{
    private string $sessionCookieName;
    private string $csrfCookieName;
    private string $password = 'a-strong-post-login-password-1';

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

    public function testFreshLearnerLoginRedirectsToDashboard(): void
    {
        $email = 'learner.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUserWithRoles($email, $this->password, [RoleKeys::APPLICANT]);
        $response = $this->login($email, $this->password);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::LEARNER_DASHBOARD, $response->getHeaderLine('Location'));
    }

    public function testFreshReviewerLoginRedirectsToQueue(): void
    {
        $email = 'reviewer.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUserWithRoles($email, $this->password, [RoleKeys::CREDENTIAL_REVIEWER]);
        $response = $this->login($email, $this->password);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::REVIEWER_QUEUE, $response->getHeaderLine('Location'));
    }

    public function testFreshFinanceLoginRedirectsToReconciliation(): void
    {
        $email = 'finance.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUserWithRoles($email, $this->password, [RoleKeys::FINANCE_ADMIN]);
        $response = $this->login($email, $this->password);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::FINANCE_RECONCILIATION, $response->getHeaderLine('Location'));
    }

    public function testFreshNotificationOperatorRedirectsToNotifications(): void
    {
        $email = 'notifops.' . bin2hex(random_bytes(3)) . '@example.test';
        $userId = $this->createUserWithRoles($email, $this->password, []);
        $this->grantPermissions($userId, ['notification.view']);
        $response = $this->login($email, $this->password);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::NOTIFICATION_OPS, $response->getHeaderLine('Location'));
    }

    public function testMultiPermissionUserFollowsPrecedenceOnFreshLogin(): void
    {
        $email = 'super.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUserWithRoles($email, $this->password, [RoleKeys::SUPER_ADMIN]);
        $response = $this->login($email, $this->password);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::REVIEWER_QUEUE, $response->getHeaderLine('Location'));
    }

    public function testAlreadyAuthenticatedUsesSameResolver(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $learnerSession = DatabaseTestCase::bindSessionForUser(
            $learner['user_id'],
            $learner['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $learnerGet = $this->getLogin($learnerSession);
        self::assertSame(302, $learnerGet->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::LEARNER_DASHBOARD, $learnerGet->getHeaderLine('Location'));

        $reviewer = DatabaseTestCase::reviewerFixture();
        $reviewerSession = DatabaseTestCase::bindSessionForUser(
            $reviewer['user_id'],
            $reviewer['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $reviewerGet = $this->getLogin($reviewerSession);
        self::assertSame(302, $reviewerGet->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::REVIEWER_QUEUE, $reviewerGet->getHeaderLine('Location'));
    }

    public function testPendingAlreadyAuthenticatedDoesNotReceivePrivilegedRedirect(): void
    {
        $pending = DatabaseTestCase::createSyntheticUser(
            'pending.redirect.' . bin2hex(random_bytes(3)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::CREDENTIAL_REVIEWER, RoleKeys::APPLICANT],
            AccountStatus::PENDING_VERIFICATION,
        );
        $session = DatabaseTestCase::bindSessionForUser(
            $pending['user_id'],
            $pending['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $response = $this->getLogin($session);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::COURSES, $response->getHeaderLine('Location'));
        self::assertNotSame(PostLoginDestinationResolver::REVIEWER_QUEUE, $response->getHeaderLine('Location'));
        self::assertNotSame(PostLoginDestinationResolver::LEARNER_DASHBOARD, $response->getHeaderLine('Location'));
    }

    public function testValidatedReturnToHonoredOnFreshAndExistingLogin(): void
    {
        $email = 'returnto.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUserWithRoles($email, $this->password, [RoleKeys::APPLICANT]);
        $fresh = $this->login($email, $this->password, '/profile');
        self::assertSame(302, $fresh->getStatusCode());
        self::assertSame('/profile', $fresh->getHeaderLine('Location'));

        $user = DatabaseTestCase::applicantFixture();
        $session = DatabaseTestCase::bindSessionForUser(
            $user['user_id'],
            $user['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $existing = $this->getLogin($session, '/profile');
        self::assertSame(302, $existing->getStatusCode());
        self::assertSame('/profile', $existing->getHeaderLine('Location'));
    }

    public function testExternalReturnToRejectedOnFreshLogin(): void
    {
        $email = 'evilreturn.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUserWithRoles($email, $this->password, [RoleKeys::APPLICANT]);
        $response = $this->login($email, $this->password, 'https://evil.example/phish');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(PostLoginDestinationResolver::LEARNER_DASHBOARD, $response->getHeaderLine('Location'));
    }

    /**
     * @param list<string> $roleKeys
     */
    private function createUserWithRoles(string $email, string $password, array $roleKeys): int
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = (new PasswordHasher())->hash($password);
        $mobile = '9' . random_int(100000000, 999999999);
        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, NULL, 1, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            strtolower($email), $now, $mobile, $now, $hash, AccountStatus::ACTIVE,
            $now, $now, 'terms.v1', $now, 'privacy.v1', 'Asia/Kolkata', $now, $now,
        ]);
        $userId = (int) $pdo->lastInsertId();
        foreach ($roleKeys as $roleKey) {
            $pdo->prepare(
                'INSERT INTO user_roles (
                    user_id, role_id, assigned_by, assigned_at, current_marker, created_at, updated_at
                 )
                 SELECT ?, role_id, NULL, ?, 1, ?, ? FROM roles WHERE role_key = ?',
            )->execute([$userId, $now, $now, $now, $roleKey]);
        }

        return $userId;
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function grantPermissions(int $userId, array $permissionKeys): void
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $roleKey = 'tmp_ops_' . bin2hex(random_bytes(3));
        $pdo->prepare(
            'INSERT INTO roles (role_key, name, is_privileged, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
        )->execute([$roleKey, 'Temporary ops', $now, $now]);
        $roleId = (int) $pdo->lastInsertId();
        foreach ($permissionKeys as $permissionKey) {
            $pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT ?, permission_id, ? FROM permissions WHERE permission_key = ?',
            )->execute([$roleId, $now, $permissionKey]);
        }
        $pdo->prepare(
            'INSERT INTO user_roles (
                user_id, role_id, assigned_by, assigned_at, current_marker, created_at, updated_at
             ) VALUES (?, ?, NULL, ?, 1, ?, ?)',
        )->execute([$userId, $roleId, $now, $now, $now]);
    }

    private function login(string $email, string $password, ?string $returnTo = null): \Psr\Http\Message\ResponseInterface
    {
        $boot = $this->bootSession();
        $body = [
            '_csrf' => $boot['csrf'],
            'email' => $email,
            'password' => $password,
        ];
        if ($returnTo !== null) {
            $body['return_to'] = $returnTo;
        }

        return ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.50'], [], 'http://localhost/login', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody($body)
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }

    /**
     * @param array{session: string, csrf: string} $boot
     */
    private function getLogin(array $boot, ?string $returnTo = null): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest([], [], 'http://localhost/login', 'GET'))
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);
        if ($returnTo !== null) {
            $request = $request->withQueryParams(['return_to' => $returnTo]);
        }

        return ApplicationFactory::handle($request);
    }

    /**
     * @return array{session: string, csrf: string}
     */
    private function bootSession(): array
    {
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
                ->withHeader('Accept', 'application/json'),
        );
        self::assertSame(200, $response->getStatusCode());
        $session = $this->cookieValue($response->getHeader('Set-Cookie'), $this->sessionCookieName);
        $csrf = $this->cookieValue($response->getHeader('Set-Cookie'), $this->csrfCookieName);
        self::assertNotNull($session);
        self::assertNotNull($csrf);

        return ['session' => $session, 'csrf' => $csrf];
    }

    /**
     * @param list<string> $headers
     */
    private function cookieValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (!str_starts_with($header, $name . '=')) {
                continue;
            }
            $pair = explode(';', $header, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq === false) {
                return null;
            }
            $value = substr($pair, $eq + 1);
            if ($value === '' || str_contains(strtolower($header), 'max-age=0')) {
                return null;
            }

            return rawurldecode($value);
        }

        return null;
    }
}
