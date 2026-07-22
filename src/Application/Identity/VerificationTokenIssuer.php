<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Notifications\NotificationOutboxPayload;
use Academy\Domain\Identity\TokenHmac;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use PDO;

/**
 * Issues email-verification / password-reset tokens with sealed delivery payload + outbox.
 * Returns raw_token to the caller only (tests / B-2b); never log it.
 */
final class VerificationTokenIssuer
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly VerificationTokenRepository $tokens,
        private readonly TokenHmac $tokenHmac,
        private readonly SealedSecretBox $sealedBox,
        private readonly OutboxWriter $outbox,
    ) {
    }

    /**
     * @return array{verification_token_id: int, raw_token: string}
     */
    public function issue(
        int $userId,
        string $purpose,
        string $destinationEmail,
        \DateTimeImmutable $expiresAt,
    ): array {
        TokenPurpose::assertValid($purpose);

        return $this->transactions->run(function (PDO $pdo) use ($userId, $purpose, $destinationEmail, $expiresAt): array {
            unset($pdo);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = $this->tokenHmac->hash($rawToken);

            $current = $this->tokens->findCurrentByUserPurposeForUpdate($userId, $purpose);
            if ($current !== null) {
                $this->tokens->clearCurrentMarker($current->verificationTokenId);
            }

            $verificationTokenId = $this->tokens->insertPendingCurrent(
                $userId,
                $purpose,
                $tokenHash,
                $expiresAt,
                $now,
            );

            $template = $purpose === TokenPurpose::PASSWORD_RESET
                ? TokenPurpose::PASSWORD_RESET
                : TokenPurpose::EMAIL_VERIFY;

            $plaintext = json_encode(
                [
                    'email' => $destinationEmail,
                    'template' => $template,
                    'link_token' => $rawToken,
                ],
                JSON_THROW_ON_ERROR,
            );

            $sealed = $this->sealedBox->seal(
                $plaintext,
                SealedSecretBox::tokenAad($verificationTokenId, $purpose, $userId),
            );
            $this->tokens->updateSealedDelivery($verificationTokenId, $sealed, $now);

            if ($purpose === TokenPurpose::PASSWORD_RESET) {
                $eventType = IdentityNotificationEventTypes::PASSWORD_RESET_SEND;
                $payload = NotificationOutboxPayload::forPasswordReset($verificationTokenId);
                $idempotencyKey = IdentityNotificationEventTypes::PASSWORD_RESET_SEND . ':' . $verificationTokenId;
            } else {
                $eventType = IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND;
                $payload = NotificationOutboxPayload::forEmailVerification($verificationTokenId);
                $idempotencyKey = IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND . ':' . $verificationTokenId;
            }

            $this->outbox->enqueue(
                $eventType,
                'verification_token',
                (string) $verificationTokenId,
                $payload,
                $idempotencyKey,
            );

            return [
                'verification_token_id' => $verificationTokenId,
                'raw_token' => $rawToken,
            ];
        });
    }
}
