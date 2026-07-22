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
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LegalAcceptancePolicy;
use Academy\Domain\Identity\MobileE164Normalizer;
use Academy\Domain\Identity\PasswordPolicy;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class RegistrationService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly UserWriteRepository $users,
        private readonly LearnerProfileRepository $learnerProfiles,
        private readonly InitialApplicantRoleBinder $roleBinder,
        private readonly VerificationTokenIssuer $tokenIssuer,
        private readonly VerificationChallengeIssuer $challengeIssuer,
        private readonly AuditService $audit,
        private readonly NotificationCapability $notifications,
        private readonly RateLimiter $rateLimiter,
        private readonly LegalAcceptancePolicy $legal,
        private readonly PasswordHasher $passwordHasher,
    ) {
    }

    public function register(
        string $email,
        string $mobile,
        string $password,
        bool $termsAccepted,
        bool $privacyAccepted,
        string $clientIp,
    ): RegistrationResult {
        if (!$this->notifications->canSendEmail()) {
            throw new ServiceUnavailableException('Email verification is temporarily unavailable.');
        }

        $this->rateLimiter->hit('auth.registration', [
            ['type' => 'ip', 'value' => $clientIp],
        ]);

        $normalizedEmail = EmailNormalizer::normalize($email);
        $normalizedMobile = MobileE164Normalizer::normalize($mobile);
        PasswordPolicy::assertAcceptable($password, $normalizedEmail, $normalizedMobile);
        $this->legal->assertAccepted($termsAccepted, $privacyAccepted);

        $passwordHash = $this->passwordHasher->hash($password);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $emailExpires = $now->modify('+24 hours');
        $otpExpires = $now->modify('+10 minutes');
        $smsAvailable = $this->notifications->canSendSms();

        try {
            return $this->transactions->run(function (PDO $pdo) use (
                $normalizedEmail,
                $normalizedMobile,
                $passwordHash,
                $now,
                $emailExpires,
                $otpExpires,
                $smsAvailable,
            ): RegistrationResult {
                try {
                    $userId = $this->users->insertPendingUser(
                        $normalizedEmail,
                        $normalizedMobile,
                        $passwordHash,
                        $this->legal->currentTermsVersion(),
                        $this->legal->currentPrivacyVersion(),
                        $now,
                    );
                } catch (PDOException $exception) {
                    if ($this->isDuplicateKey($exception)) {
                        throw new DuplicateRegistrationSignal();
                    }

                    throw $exception;
                }

                $this->learnerProfiles->insertStub($userId, $now);
                $this->roleBinder->bind($pdo, $userId, $now, $this->audit);

                $emailIssued = $this->tokenIssuer->issueInAmbientTx(
                    $userId,
                    TokenPurpose::EMAIL_VERIFY,
                    $normalizedEmail,
                    $emailExpires,
                    $now,
                );

                $this->audit->record(
                    new IdentityRegistrationAuditPayload(
                        action: 'identity.registered',
                        entityType: 'user',
                        entityId: (string) $userId,
                        next: [
                            'user_id' => $userId,
                            'account_status' => AccountStatus::PENDING_VERIFICATION,
                        ],
                    ),
                    actorType: 'system',
                    actorUserId: null,
                    source: 'registration',
                    occurredAt: $now,
                );

                $this->audit->record(
                    new IdentityRegistrationAuditPayload(
                        action: 'identity.email_verification_requested',
                        entityType: 'verification_token',
                        entityId: (string) $emailIssued['verification_token_id'],
                        next: [
                            'user_id' => $userId,
                            'verification_token_id' => $emailIssued['verification_token_id'],
                            'purpose' => TokenPurpose::EMAIL_VERIFY,
                        ],
                    ),
                    actorType: 'system',
                    actorUserId: null,
                    source: 'registration',
                    occurredAt: $now,
                );

                if ($smsAvailable) {
                    $challengeIssued = $this->challengeIssuer->issueInAmbientTx(
                        $userId,
                        $normalizedMobile,
                        $otpExpires,
                        10,
                        $now,
                    );

                    $this->audit->record(
                        new IdentityRegistrationAuditPayload(
                            action: 'identity.mobile_otp_requested',
                            entityType: 'verification_challenge',
                            entityId: (string) $challengeIssued['verification_challenge_id'],
                            next: [
                                'user_id' => $userId,
                                'verification_challenge_id' => $challengeIssued['verification_challenge_id'],
                                'channel' => 'sms',
                            ],
                        ),
                        actorType: 'system',
                        actorUserId: null,
                        source: 'registration',
                        occurredAt: $now,
                    );
                }

                return new RegistrationResult(created: true, userId: $userId);
            });
        } catch (DuplicateRegistrationSignal) {
            return new RegistrationResult(created: false, userId: null);
        }
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
