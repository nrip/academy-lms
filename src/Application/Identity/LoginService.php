<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\SessionService;
use Academy\Domain\Audit\IdentityAuthAuditPayload;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\AuthVersion;
use Academy\Domain\Identity\EmailNormalizer;
use Academy\Domain\Identity\LoginEligibility;
use Academy\Domain\Identity\LoginLockoutPolicy;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Security\SessionRecord;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Outcome of a successful login domain transaction (session mutation happens after commit).
 */
final class LoginSuccess
{
    public function __construct(
        public readonly int $userId,
        public readonly int $authVersion,
        public readonly bool $passwordRehashed,
    ) {
    }
}

/**
 * Authenticates credentials and prepares bind data. Session writes happen outside this service's TX.
 *
 * Failed-login counter updates commit before AuthenticationException is thrown so lockout
 * state is never rolled back with the failure signal.
 */
final class LoginService
{
    public const GENERIC_FAILURE = 'Unable to sign in with those details. If you continue to have trouble, contact support.';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly UserWriteRepository $users,
        private readonly PasswordHasher $passwordHasher,
        private readonly AuditService $audit,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(string $email, string $password, string $clientIp): LoginSuccess
    {
        try {
            $normalizedEmail = EmailNormalizer::normalize($email);
        } catch (ValidationException) {
            $this->rateLimiter->hit('auth.login', [
                ['type' => 'ip', 'value' => $clientIp],
                ['type' => 'email', 'value' => 'invalid@invalid.invalid'],
            ]);
            $this->passwordHasher->verifyDummy($password);
            throw new AuthenticationException(self::GENERIC_FAILURE);
        }

        $this->rateLimiter->hit('auth.login', [
            ['type' => 'ip', 'value' => $clientIp],
            ['type' => 'email', 'value' => $normalizedEmail],
        ]);

        /** @var LoginSuccess|null $success */
        $success = $this->transactions->run(function (PDO $pdo) use ($normalizedEmail, $password): ?LoginSuccess {
            unset($pdo);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $user = $this->users->findCredentialsByEmailForUpdate($normalizedEmail);
            if ($user === null) {
                $this->passwordHasher->verifyDummy($password);

                return null;
            }

            $lockedUntil = $user['locked_until'] !== null
                ? new DateTimeImmutable($user['locked_until'], new DateTimeZone('UTC'))
                : null;
            $windowStarted = $user['failed_login_window_started_at'] !== null
                ? new DateTimeImmutable($user['failed_login_window_started_at'], new DateTimeZone('UTC'))
                : null;

            $eligibility = LoginEligibility::evaluate([
                'account_status' => $user['account_status'],
                'email_verified_at' => $user['email_verified_at'],
                'locked_until' => $lockedUntil,
            ], $now);

            $passwordOk = $this->passwordHasher->verify($password, $user['password_hash']);

            if ($eligibility !== LoginEligibility::REASON_OK || !$passwordOk) {
                $failure = LoginLockoutPolicy::recordFailure([
                    'failed_login_count' => $user['failed_login_count'],
                    'failed_login_window_started_at' => $windowStarted,
                    'locked_until' => $lockedUntil,
                ], $now);

                $this->users->applyFailedLogin($user['user_id'], $failure);

                if ($failure['newly_locked']) {
                    $this->audit->record(
                        new IdentityAuthAuditPayload(
                            action: 'identity.account_temporarily_locked',
                            entityType: 'user',
                            entityId: (string) $user['user_id'],
                            next: [
                                'user_id' => $user['user_id'],
                                'result' => 'locked',
                                'reason_code' => 'failed_login_threshold',
                                'lockout_seconds' => LoginLockoutPolicy::LOCK_SECONDS,
                                'locked_until' => $failure['locked_until']?->format('Y-m-d H:i:s.u'),
                                'failed_login_count' => $failure['failed_login_count'],
                            ],
                        ),
                        actorType: 'system',
                        actorUserId: null,
                        source: 'login',
                        occurredAt: $now,
                    );
                } else {
                    $this->audit->record(
                        new IdentityAuthAuditPayload(
                            action: 'identity.login_failed',
                            entityType: 'user',
                            entityId: (string) $user['user_id'],
                            next: [
                                'user_id' => $user['user_id'],
                                'result' => 'failed',
                                'reason_code' => $eligibility !== LoginEligibility::REASON_OK
                                    ? $eligibility
                                    : 'invalid_credentials',
                                'failed_login_count' => $failure['failed_login_count'],
                            ],
                        ),
                        actorType: 'system',
                        actorUserId: null,
                        source: 'login',
                        occurredAt: $now,
                    );
                }

                return null;
            }

            $rehash = null;
            if ($this->passwordHasher->needsRehash($user['password_hash'])) {
                $rehash = $this->passwordHasher->hash($password);
            }

            $successState = LoginLockoutPolicy::recordSuccess($now);
            $this->users->applySuccessfulLogin($user['user_id'], $successState, $rehash);

            $authVersion = AuthVersion::fromDatabase($user['auth_version']);

            $this->audit->record(
                new IdentityAuthAuditPayload(
                    action: 'identity.login_succeeded',
                    entityType: 'user',
                    entityId: (string) $user['user_id'],
                    next: [
                        'user_id' => $user['user_id'],
                        'result' => 'succeeded',
                        'auth_version_before' => $authVersion,
                        'auth_version_after' => $authVersion,
                    ],
                ),
                actorType: 'user',
                actorUserId: $user['user_id'],
                source: 'login',
                occurredAt: $now,
            );

            return new LoginSuccess(
                userId: $user['user_id'],
                authVersion: $authVersion,
                passwordRehashed: $rehash !== null,
            );
        });

        if (!$success instanceof LoginSuccess) {
            throw new AuthenticationException(self::GENERIC_FAILURE);
        }

        return $success;
    }

    /**
     * Post-commit session binding: regenerate ID, bind user, clear pending markers.
     *
     * @return array{record: SessionRecord, raw_token: string, raw_csrf: string}
     */
    public function establishSession(
        SessionService $sessions,
        SessionRecord $session,
        LoginSuccess $success,
    ): array {
        $rotated = $sessions->regenerate($session);
        $bound = $sessions->bindUser(
            $rotated['record'],
            $success->userId,
            $success->authVersion,
            ['auth_stage' => AuthStage::FULLY_AUTHENTICATED],
        );

        return [
            'record' => $bound,
            'raw_token' => $rotated['raw_token'],
            'raw_csrf' => $rotated['raw_csrf'],
        ];
    }
}
