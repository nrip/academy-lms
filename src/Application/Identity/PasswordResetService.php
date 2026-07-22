<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\SessionService;
use Academy\Domain\Audit\IdentityAuthAuditPayload;
use Academy\Domain\Audit\IdentityTokenAuditPayload;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\PasswordPolicy;
use Academy\Domain\Identity\PasswordResetAuthorizationRepository;
use Academy\Domain\Identity\TokenConfirmationContextRepository;
use Academy\Domain\Identity\TokenHmac;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Scanner-safe password-reset confirmation + password update.
 *
 * Confirm POST consumes the verification token and issues a short-lived
 * password_reset_authorization (separate from the raw email token).
 * Password submission consumes that authorization, updates the hash,
 * increments auth_version, then post-commit revokeAllForUser.
 */
final class PasswordResetService
{
    private const GENERIC_INVALID = 'This password reset link is no longer valid.';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly VerificationTokenRepository $tokens,
        private readonly TokenConfirmationContextRepository $contexts,
        private readonly PasswordResetAuthorizationRepository $authorizations,
        private readonly UserWriteRepository $users,
        private readonly TokenHmac $tokenHmac,
        private readonly PasswordHasher $passwordHasher,
        private readonly AuditService $audit,
        private readonly RateLimiter $rateLimiter,
        private readonly SessionService $sessions,
        private readonly int $authorizationTtlSeconds = 900,
    ) {
    }

    /**
     * POST confirmation: lock context then token; consume both; create reset authorization.
     *
     * @return array{authorization_secret: string, user_id: int}
     */
    public function confirm(string $rawConfirmationSecret): array
    {
        try {
            return $this->transactions->run(function (PDO $pdo) use ($rawConfirmationSecret): array {
                unset($pdo);
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                if ($rawConfirmationSecret === '' || strlen($rawConfirmationSecret) !== 64 || !ctype_xdigit($rawConfirmationSecret)) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                $confirmationHash = $this->tokenHmac->hash(strtolower($rawConfirmationSecret));
                $context = $this->contexts->findByHashForUpdate($confirmationHash);
                if ($context === null || !$context->isUsable($now) || $context->purpose !== TokenPurpose::PASSWORD_RESET) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                $token = $this->tokens->findByIdForUpdate($context->verificationTokenId);
                if ($token === null || !$token->isUsable($now) || $token->purpose !== TokenPurpose::PASSWORD_RESET) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                if (!$this->tokens->conditionalConsumeById($token->verificationTokenId, $now)) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                if (!$this->contexts->markConsumed($context->tokenConfirmationContextId, $now)) {
                    throw new DomainRuleException(self::GENERIC_INVALID);
                }

                $rawAuthorizationSecret = bin2hex(random_bytes(32));
                $authorizationHash = $this->tokenHmac->hash($rawAuthorizationSecret);
                $expiresAt = $now->modify('+' . max(1, $this->authorizationTtlSeconds) . ' seconds');

                $authorizationId = $this->authorizations->insert(
                    $token->userId,
                    $token->verificationTokenId,
                    $authorizationHash,
                    $expiresAt,
                    $now,
                );

                $this->audit->record(
                    new IdentityTokenAuditPayload(
                        'identity.token_context_consumed',
                        'token_confirmation_context',
                        (string) $context->tokenConfirmationContextId,
                        previous: [
                            'context_id' => $context->tokenConfirmationContextId,
                            'verification_token_id' => $token->verificationTokenId,
                            'user_id' => $token->userId,
                            'purpose' => $token->purpose,
                        ],
                        next: [
                            'context_id' => $context->tokenConfirmationContextId,
                            'verification_token_id' => $token->verificationTokenId,
                            'user_id' => $token->userId,
                            'purpose' => $token->purpose,
                        ],
                    ),
                    'system',
                    null,
                    'http',
                );

                unset($authorizationId);

                return [
                    'authorization_secret' => $rawAuthorizationSecret,
                    'user_id' => $token->userId,
                ];
            });
        } catch (DomainRuleException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new DomainRuleException(self::GENERIC_INVALID);
        }
    }

    /**
     * Complete password reset using a confirmed authorization secret.
     * Does not auto-login. Session revocation is post-commit.
     */
    public function complete(
        string $rawAuthorizationSecret,
        string $newPassword,
        string $clientIp,
    ): void {
        if ($rawAuthorizationSecret === '' || strlen($rawAuthorizationSecret) !== 64 || !ctype_xdigit($rawAuthorizationSecret)) {
            throw new DomainRuleException(self::GENERIC_INVALID);
        }

        $this->rateLimiter->hit('auth.password_reset.submit', [
            ['type' => 'ip', 'value' => $clientIp],
            ['type' => 'token_hmac', 'value' => $this->tokenHmac->hash(strtolower($rawAuthorizationSecret))],
        ]);

        // Hash before the domain TX (Argon2id is intentionally expensive).
        // Full PasswordPolicy (email/mobile inequality) runs inside the TX after user lock.
        if (strlen($newPassword) < 12 || strlen($newPassword) > 128) {
            throw new ValidationException('Password does not meet policy requirements.', [
                'password' => ['Password must be between 12 and 128 characters.'],
            ]);
        }

        $passwordHash = $this->passwordHasher->hash($newPassword);

        $result = $this->transactions->run(function (PDO $pdo) use (
            $rawAuthorizationSecret,
            $newPassword,
            $passwordHash,
        ): array {
            unset($pdo);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $authorizationHash = $this->tokenHmac->hash(strtolower($rawAuthorizationSecret));
            $auth = $this->authorizations->findByHashForUpdate($authorizationHash);

            // Idempotent replay: already consumed → generic success path for caller.
            if ($auth !== null && $auth['consumed_at'] !== null) {
                return [
                    'status' => 'already_completed',
                    'user_id' => $auth['user_id'],
                    'auth_version_before' => null,
                    'auth_version_after' => null,
                    'authorization_id' => $auth['password_reset_authorization_id'],
                ];
            }

            if (!$this->authorizations->isUsable($auth, $now) || $auth === null) {
                throw new DomainRuleException(self::GENERIC_INVALID);
            }

            $user = $this->users->findByIdForUpdate($auth['user_id']);
            if ($user === null) {
                throw new DomainRuleException(self::GENERIC_INVALID);
            }

            try {
                PasswordPolicy::assertAcceptable($newPassword, $user['email'], $user['mobile_e164']);
            } catch (ValidationException $exception) {
                throw $exception;
            }

            $versions = $this->users->applyPasswordReset($auth['user_id'], $passwordHash, $now);

            if (!$this->authorizations->markConsumed($auth['password_reset_authorization_id'], $now)) {
                throw new DomainRuleException(self::GENERIC_INVALID);
            }

            $this->audit->record(
                new IdentityAuthAuditPayload(
                    action: 'identity.password_reset_completed',
                    entityType: 'user',
                    entityId: (string) $auth['user_id'],
                    previous: [
                        'user_id' => $auth['user_id'],
                        'auth_version_before' => $versions['auth_version_before'],
                    ],
                    next: [
                        'user_id' => $auth['user_id'],
                        'result' => 'completed',
                        'auth_version_before' => $versions['auth_version_before'],
                        'auth_version_after' => $versions['auth_version_after'],
                        'password_reset_authorization_id' => $auth['password_reset_authorization_id'],
                    ],
                ),
                actorType: 'user',
                actorUserId: $auth['user_id'],
                source: 'password_reset',
                occurredAt: $now,
            );

            return [
                'status' => 'completed',
                'user_id' => $auth['user_id'],
                'auth_version_before' => $versions['auth_version_before'],
                'auth_version_after' => $versions['auth_version_after'],
                'authorization_id' => $auth['password_reset_authorization_id'],
            ];
        });

        if ($result['status'] === 'already_completed') {
            return;
        }

        $revoked = $this->sessions->revokeAllForUser($result['user_id']);

        $this->audit->record(
            new IdentityAuthAuditPayload(
                action: 'identity.sessions_revoked_after_password_reset',
                entityType: 'user',
                entityId: (string) $result['user_id'],
                next: [
                    'user_id' => $result['user_id'],
                    'result' => 'sessions_revoked',
                    'sessions_revoked' => $revoked,
                    'auth_version_after' => $result['auth_version_after'],
                    'password_reset_authorization_id' => $result['authorization_id'],
                ],
            ),
            actorType: 'system',
            actorUserId: null,
            source: 'password_reset',
        );
    }
}
