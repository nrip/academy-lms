<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Domain\Audit\IdentityRegistrationAuditPayload;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\MobileE164Normalizer;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MobileOtpResendService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly UserWriteRepository $users,
        private readonly VerificationChallengeIssuer $challengeIssuer,
        private readonly AuditService $audit,
        private readonly NotificationCapability $notifications,
        private readonly RateLimiter $rateLimiter,
        private readonly RateLimitKeyFactory $rateLimitKeys,
    ) {
    }

    public function resend(?int $pendingUserId, ?string $rawMobile, string $clientIp): void
    {
        if (!$this->notifications->canSendSms()) {
            throw new ServiceUnavailableException('SMS verification is temporarily unavailable.');
        }

        $user = $this->resolveUser($pendingUserId, $rawMobile);
        if ($user === null) {
            return;
        }

        $mobileDimension = $this->rateLimitKeys->mobileE164Dimension($user['mobile_e164']);
        $this->rateLimiter->hit('auth.otp_send.cooldown', [
            ['type' => 'ip', 'value' => $clientIp],
            $mobileDimension,
        ]);
        $this->rateLimiter->hit('auth.otp_send.15m', [
            ['type' => 'ip', 'value' => $clientIp],
            $mobileDimension,
        ]);
        $this->rateLimiter->hit('auth.otp_send.24h', [
            ['type' => 'ip', 'value' => $clientIp],
            $mobileDimension,
        ]);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $otpExpires = $now->modify('+10 minutes');

        $this->transactions->run(function (PDO $pdo) use ($user, $now, $otpExpires): void {
            unset($pdo);

            $issued = $this->challengeIssuer->issueInAmbientTx(
                $user['user_id'],
                $user['mobile_e164'],
                $otpExpires,
                10,
                $now,
            );

            $this->audit->record(
                new IdentityRegistrationAuditPayload(
                    action: 'identity.mobile_otp_requested',
                    entityType: 'verification_challenge',
                    entityId: (string) $issued['verification_challenge_id'],
                    next: [
                        'user_id' => $user['user_id'],
                        'verification_challenge_id' => $issued['verification_challenge_id'],
                        'channel' => 'sms',
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
    private function resolveUser(?int $pendingUserId, ?string $rawMobile): ?array
    {
        if ($pendingUserId !== null) {
            return $this->users->findById($pendingUserId);
        }

        if ($rawMobile !== null && $rawMobile !== '') {
            $normalizedMobile = MobileE164Normalizer::normalize($rawMobile);
            $byMobile = $this->users->findByMobileE164($normalizedMobile);
            if ($byMobile === null) {
                return null;
            }

            return $this->users->findById($byMobile['user_id']);
        }

        return null;
    }
}
