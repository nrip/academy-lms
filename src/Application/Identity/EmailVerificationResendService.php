<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Application\Security\RateLimiter;
use Academy\Domain\Audit\IdentityRegistrationAuditPayload;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\EmailNormalizer;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class EmailVerificationResendService
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

    public function resend(?int $pendingVerificationUserId, ?string $emailIdentifier, string $clientIp): void
    {
        if (!$this->notifications->canSendEmail()) {
            throw new ServiceUnavailableException('Email verification is temporarily unavailable.');
        }

        $user = $this->resolveUser($pendingVerificationUserId, $emailIdentifier, $clientIp);
        if ($user === null || !$this->isEligibleForResend($user)) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $emailExpires = $now->modify('+24 hours');

        $this->transactions->run(function (PDO $pdo) use ($user, $now, $emailExpires): void {
            unset($pdo);

            $issued = $this->tokenIssuer->issueInAmbientTx(
                $user['user_id'],
                TokenPurpose::EMAIL_VERIFY,
                $user['email'],
                $emailExpires,
                $now,
            );

            $this->audit->record(
                new IdentityRegistrationAuditPayload(
                    action: 'identity.email_verification_requested',
                    entityType: 'verification_token',
                    entityId: (string) $issued['verification_token_id'],
                    next: [
                        'user_id' => $user['user_id'],
                        'verification_token_id' => $issued['verification_token_id'],
                        'purpose' => TokenPurpose::EMAIL_VERIFY,
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'http',
                occurredAt: $now,
            );
        });
    }

    /**
     * @return array{
     *   user_id: int,
     *   account_status: string,
     *   email: string,
     *   mobile_e164: string,
     *   email_verified_at: ?string,
     *   mobile_verified_at: ?string
     * }|null
     */
    private function resolveUser(?int $pendingVerificationUserId, ?string $emailIdentifier, string $clientIp): ?array
    {
        if ($emailIdentifier !== null && $emailIdentifier !== '') {
            $normalizedEmail = EmailNormalizer::normalize($emailIdentifier);
            $this->rateLimiter->hit('auth.email_verify.resend', [
                ['type' => 'ip', 'value' => $clientIp],
                ['type' => 'email', 'value' => $normalizedEmail],
            ]);

            $byEmail = $this->users->findByEmail($normalizedEmail);
            if ($byEmail === null) {
                return null;
            }

            return $this->users->findById($byEmail['user_id']);
        }

        if ($pendingVerificationUserId !== null) {
            $this->rateLimiter->hit('auth.email_verify.resend', [
                ['type' => 'ip', 'value' => $clientIp],
                ['type' => 'user_id', 'value' => (string) $pendingVerificationUserId],
            ]);

            return $this->users->findById($pendingVerificationUserId);
        }

        return null;
    }

    /**
     * @param array{
     *   user_id: int,
     *   account_status: string,
     *   email: string,
     *   mobile_e164: string,
     *   email_verified_at: ?string,
     *   mobile_verified_at: ?string
     * } $user
     */
    private function isEligibleForResend(array $user): bool
    {
        if ($user['email_verified_at'] !== null) {
            return false;
        }

        return in_array($user['account_status'], [
            AccountStatus::PENDING_VERIFICATION,
            AccountStatus::ACTIVE,
            AccountStatus::SUSPENDED,
        ], true);
    }
}
