<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Domain\Audit\IdentityRegistrationAuditPayload;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\MobileE164Normalizer;
use Academy\Domain\Identity\OtpHmac;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Identity\VerificationChallengeRecord;
use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MobileOtpVerificationService
{
    private const CHANNEL_SMS = 'sms';
    private const GENERIC_INVALID = 'The verification code is not valid.';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly UserWriteRepository $users,
        private readonly VerificationChallengeRepository $challenges,
        private readonly OtpHmac $otpHmac,
        private readonly AuditService $audit,
        private readonly RateLimiter $rateLimiter,
        private readonly RateLimitKeyFactory $rateLimitKeys,
    ) {
    }

    public function verify(?int $pendingUserId, ?string $rawMobile, string $otp, string $clientIp): void
    {
        $user = $this->resolveUser($pendingUserId, $rawMobile);
        if ($user === null) {
            throw new DomainRuleException(self::GENERIC_INVALID);
        }

        $this->rateLimiter->hit('auth.otp_verify', [
            ['type' => 'ip', 'value' => $clientIp],
            $this->rateLimitKeys->mobileE164Dimension($user['mobile_e164']),
        ]);

        // Attempt increments must commit even when the OTP is wrong; throw only after TX.
        $outcome = $this->transactions->run(function (PDO $pdo) use ($user, $otp): string {
            unset($pdo);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $challenge = $this->challenges->findCurrentByUserChannelForUpdate($user['user_id'], self::CHANNEL_SMS);
            if ($challenge === null || !$this->isChallengeUsable($challenge, $now)) {
                return 'invalid';
            }

            $this->challenges->incrementAttempt($challenge->verificationChallengeId);

            if (!$this->otpMatches($challenge, $otp, $user['mobile_e164'])) {
                return 'invalid';
            }

            if (!$this->challenges->conditionalConsumeById($challenge->verificationChallengeId, $now)) {
                return 'invalid';
            }

            $mobileWasUnverified = $this->users->applyMobileVerification($user['user_id'], $now);

            $this->audit->record(
                new IdentityRegistrationAuditPayload(
                    action: 'identity.mobile_verified',
                    entityType: 'user',
                    entityId: (string) $user['user_id'],
                    previous: [
                        'user_id' => $user['user_id'],
                        'verification_challenge_id' => $challenge->verificationChallengeId,
                        'channel' => self::CHANNEL_SMS,
                        'mobile_verified' => $mobileWasUnverified ? 0 : 1,
                    ],
                    next: [
                        'user_id' => $user['user_id'],
                        'verification_challenge_id' => $challenge->verificationChallengeId,
                        'channel' => self::CHANNEL_SMS,
                        'mobile_verified' => 1,
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'http',
                occurredAt: $now,
            );

            return 'ok';
        });

        if ($outcome !== 'ok') {
            throw new DomainRuleException(self::GENERIC_INVALID);
        }
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

    private function isChallengeUsable(VerificationChallengeRecord $challenge, DateTimeImmutable $now): bool
    {
        if (!$challenge->isCurrent() || $challenge->consumedAt !== null) {
            return false;
        }

        if ($challenge->expiresAt <= $now) {
            return false;
        }

        return $challenge->attemptCount < $challenge->maxAttempts;
    }

    private function otpMatches(
        VerificationChallengeRecord $challenge,
        string $otp,
        string $mobileE164,
    ): bool {
        if (!preg_match('/^\d{6}$/', $otp)) {
            return false;
        }

        $destinationHmac = $this->otpHmac->hashDestination($mobileE164);
        if (!$this->otpHmac->equals($destinationHmac, $challenge->destinationHmac)) {
            return false;
        }

        $computed = $this->otpHmac->hashOtp($challenge->otpBindingNonce, $otp);

        return $this->otpHmac->equals($computed, $challenge->otpHmac);
    }
}
