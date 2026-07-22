<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Application\Security\RateLimiter;
use Academy\Domain\Audit\IdentityAuthAuditPayload;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\EmailNormalizer;
use Academy\Domain\Identity\LoginEligibility;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Forgot-password request: generic success whether or not the account exists.
 * Suspended users do not receive a usable reset token (default deny).
 */
final class ForgotPasswordService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly UserWriteRepository $users,
        private readonly VerificationTokenIssuer $tokenIssuer,
        private readonly AuditService $audit,
        private readonly NotificationCapability $notifications,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function request(string $email, string $clientIp): void
    {
        if (!$this->notifications->canSendEmail()) {
            throw new ServiceUnavailableException('Password reset is temporarily unavailable.');
        }

        try {
            $normalizedEmail = EmailNormalizer::normalize($email);
        } catch (\Academy\Domain\Exception\ValidationException) {
            // Still rate-limit malformed attempts under a stable bucket dimension.
            $this->rateLimiter->hit('auth.forgot_password', [
                ['type' => 'ip', 'value' => $clientIp],
                ['type' => 'email', 'value' => 'invalid@invalid.invalid'],
            ]);

            return;
        }

        $this->rateLimiter->hit('auth.forgot_password', [
            ['type' => 'ip', 'value' => $clientIp],
            ['type' => 'email', 'value' => $normalizedEmail],
        ]);

        $byEmail = $this->users->findByEmail($normalizedEmail);
        if ($byEmail === null) {
            return;
        }

        $user = $this->users->findById($byEmail['user_id']);
        if ($user === null || !LoginEligibility::mayReceivePasswordReset($user['account_status'])) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify('+1 hour');

        $this->transactions->run(function (PDO $pdo) use ($user, $now, $expires): void {
            unset($pdo);

            $issued = $this->tokenIssuer->issueInAmbientTx(
                $user['user_id'],
                TokenPurpose::PASSWORD_RESET,
                $user['email'],
                $expires,
                $now,
            );

            $this->audit->record(
                new IdentityAuthAuditPayload(
                    action: 'identity.password_reset_requested',
                    entityType: 'verification_token',
                    entityId: (string) $issued['verification_token_id'],
                    next: [
                        'user_id' => $user['user_id'],
                        'result' => 'requested',
                        'verification_token_id' => $issued['verification_token_id'],
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'forgot_password',
                occurredAt: $now,
            );
        });
    }
}
