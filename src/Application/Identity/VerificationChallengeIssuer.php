<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Notifications\NotificationOutboxPayload;
use Academy\Domain\Identity\OtpHmac;
use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use PDO;

/**
 * Issues SMS OTP challenges with sealed delivery payload + outbox.
 * Returns otp to the caller only for tests; never log it.
 */
final class VerificationChallengeIssuer
{
    private const CHANNEL_SMS = 'sms';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly VerificationChallengeRepository $challenges,
        private readonly OtpHmac $otpHmac,
        private readonly SealedSecretBox $sealedBox,
        private readonly OutboxWriter $outbox,
    ) {
    }

    /**
     * @return array{verification_challenge_id: int, otp: string}
     */
    public function issue(
        int $userId,
        string $destinationE164,
        \DateTimeImmutable $expiresAt,
        int $maxAttempts = 10,
    ): array {
        return $this->transactions->run(function (PDO $pdo) use ($userId, $destinationE164, $expiresAt, $maxAttempts): array {
            unset($pdo);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $otp = sprintf('%06d', random_int(0, 999999));
            $nonce = random_bytes(16);
            $destinationHmac = $this->otpHmac->hashDestination($destinationE164);
            $otpHmac = $this->otpHmac->hashOtp($nonce, $otp);

            $current = $this->challenges->findCurrentByUserChannelForUpdate($userId, self::CHANNEL_SMS);
            if ($current !== null) {
                $this->challenges->clearCurrentMarker($current->verificationChallengeId);
            }

            $verificationChallengeId = $this->challenges->insertPendingCurrent(
                $userId,
                self::CHANNEL_SMS,
                $destinationHmac,
                $nonce,
                $otpHmac,
                $expiresAt,
                $maxAttempts,
                $now,
            );

            $plaintext = json_encode(
                [
                    'otp' => $otp,
                    'e164' => $destinationE164,
                ],
                JSON_THROW_ON_ERROR,
            );

            $sealed = $this->sealedBox->seal(
                $plaintext,
                SealedSecretBox::challengeAad($verificationChallengeId, self::CHANNEL_SMS, $userId),
            );
            $this->challenges->updateSealedDelivery($verificationChallengeId, $sealed, $now);

            $payload = NotificationOutboxPayload::forMobileOtp($verificationChallengeId, self::CHANNEL_SMS);
            $this->outbox->enqueue(
                IdentityNotificationEventTypes::MOBILE_OTP_SEND,
                'verification_challenge',
                (string) $verificationChallengeId,
                $payload,
                IdentityNotificationEventTypes::MOBILE_OTP_SEND . ':' . $verificationChallengeId,
            );

            return [
                'verification_challenge_id' => $verificationChallengeId,
                'otp' => $otp,
            ];
        });
    }
}
