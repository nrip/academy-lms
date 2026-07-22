<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Domain\Notifications\DeliveryStatus;
use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Domain\Notifications\SealedSecret;
use Academy\Domain\Notifications\SmsDeliveryMessage;
use Academy\Domain\Notifications\SmsOtpDeliveryPort;
use Academy\Domain\Outbox\OutboxMessage;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims identity notification outbox events, decrypts sealed delivery, sends via ports,
 * then finalises delivery status. Provider I/O is always outside DB transactions.
 */
final class IdentityNotificationDeliveryWorker
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly VerificationTokenRepository $tokens,
        private readonly VerificationChallengeRepository $challenges,
        private readonly SealedSecretBox $sealedBox,
        private readonly EmailDeliveryPort $emailPort,
        private readonly SmsOtpDeliveryPort $smsPort,
        private readonly DeliveryFinaliser $finaliser,
        private readonly LoggerInterface $logger,
        private readonly int $leaseSeconds,
        private readonly int $maxAttempts,
        private readonly int $backoffBaseSeconds,
        private readonly int $backoffCapSeconds,
    ) {
    }

    /**
     * @return int Number of messages successfully finalised or idempotently published
     */
    public function run(string $workerId, int $limit = 10): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $this->outbox->claimByEventTypes(
            $workerId,
            $now,
            $this->leaseSeconds,
            IdentityNotificationEventTypes::all(),
            $limit,
        );
        $processed = 0;

        foreach ($claimed as $message) {
            try {
                if ($this->processOne($message)) {
                    ++$processed;
                }
            } catch (Throwable $exception) {
                $this->logger->warning('Identity notification delivery failed.', [
                    'outbox_message_id' => $message->id,
                    'event_type' => $message->eventType,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $processed;
    }

    private function processOne(OutboxMessage $message): bool
    {
        return match ($message->eventType) {
            IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND,
            IdentityNotificationEventTypes::PASSWORD_RESET_SEND => $this->processTokenMessage($message),
            IdentityNotificationEventTypes::MOBILE_OTP_SEND => $this->processChallengeMessage($message),
            default => false,
        };
    }

    private function processTokenMessage(OutboxMessage $message): bool
    {
        $recordId = $this->intPayload($message->payload, 'verification_token_id');
        $record = $this->tokens->findById($recordId);
        if ($record === null) {
            $this->finaliser->finalizeTerminal('token', $recordId, $message, 'verification token missing');

            return true;
        }

        if ($record->deliveryStatus === DeliveryStatus::DELIVERED) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return $this->outbox->markPublished(
                $message->id,
                $message->lockedBy,
                $message->claimToken,
                $now,
            );
        }

        if (
            $record->deliveryStatus !== DeliveryStatus::PENDING
            || $record->deliveryCiphertext === null
            || $record->deliveryNonce === null
            || $record->deliveryKeyVersion === null
        ) {
            $this->finaliser->finalizeTerminal('token', $recordId, $message, 'sealed delivery missing');

            return true;
        }

        try {
            $plaintext = $this->sealedBox->unseal(
                new SealedSecret(
                    $record->deliveryCiphertext,
                    $record->deliveryNonce,
                    $record->deliveryKeyVersion,
                ),
                SealedSecretBox::tokenAad($record->verificationTokenId, $record->purpose, $record->userId),
            );
            /** @var array{email?: mixed, template?: mixed, link_token?: mixed} $data */
            $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
            $emailMessage = new EmailDeliveryMessage(
                (string) ($data['email'] ?? ''),
                (string) ($data['template'] ?? $record->purpose),
                $record->purpose === 'password_reset' ? 'Password reset' : 'Verify your email',
                'token=' . (string) ($data['link_token'] ?? ''),
                $message->idempotencyKey,
            );

            // Provider I/O outside any DB transaction.
            $receipt = $this->emailPort->send($emailMessage);
            $this->finaliser->finalizeDelivered('token', $recordId, $message, $receipt->providerMessageId);

            return true;
        } catch (Throwable $exception) {
            return $this->handleSendFailure('token', $recordId, $message, $exception);
        }
    }

    private function processChallengeMessage(OutboxMessage $message): bool
    {
        $recordId = $this->intPayload($message->payload, 'verification_challenge_id');
        $record = $this->challenges->findById($recordId);
        if ($record === null) {
            $this->finaliser->finalizeTerminal('challenge', $recordId, $message, 'verification challenge missing');

            return true;
        }

        if ($record->deliveryStatus === DeliveryStatus::DELIVERED) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return $this->outbox->markPublished(
                $message->id,
                $message->lockedBy,
                $message->claimToken,
                $now,
            );
        }

        if (
            $record->deliveryStatus !== DeliveryStatus::PENDING
            || $record->otpDeliveryCiphertext === null
            || $record->otpDeliveryNonce === null
            || $record->otpDeliveryKeyVersion === null
        ) {
            $this->finaliser->finalizeTerminal('challenge', $recordId, $message, 'sealed delivery missing');

            return true;
        }

        try {
            $plaintext = $this->sealedBox->unseal(
                new SealedSecret(
                    $record->otpDeliveryCiphertext,
                    $record->otpDeliveryNonce,
                    $record->otpDeliveryKeyVersion,
                ),
                SealedSecretBox::challengeAad($record->verificationChallengeId, $record->channel, $record->userId),
            );
            /** @var array{otp?: mixed, e164?: mixed} $data */
            $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
            $otp = (string) ($data['otp'] ?? '');
            $smsMessage = new SmsDeliveryMessage(
                (string) ($data['e164'] ?? ''),
                'mobile_otp',
                'Your verification code is ' . $otp,
                $message->idempotencyKey,
            );

            // Provider I/O outside any DB transaction.
            $receipt = $this->smsPort->send($smsMessage);
            $this->finaliser->finalizeDelivered('challenge', $recordId, $message, $receipt->providerMessageId);

            return true;
        } catch (Throwable $exception) {
            return $this->handleSendFailure('challenge', $recordId, $message, $exception);
        }
    }

    private function handleSendFailure(
        string $kind,
        int $recordId,
        OutboxMessage $message,
        Throwable $exception,
    ): bool {
        $redacted = $this->redactError($exception);

        if ($message->attemptCount >= $this->maxAttempts) {
            $this->finaliser->finalizeTerminal($kind, $recordId, $message, $redacted);

            return true;
        }

        // Leave ciphertext in place for retry.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $backoff = $this->backoffSeconds($message->attemptCount);
        $accepted = $this->outbox->markRetryOrDead(
            $message->id,
            $message->lockedBy,
            $message->claimToken,
            $message->attemptCount,
            $this->maxAttempts,
            $redacted,
            $now,
            $backoff,
        );

        if (!$accepted) {
            $this->logger->warning('Outbox markRetryOrDead skipped: lease ownership lost.', [
                'outbox_message_id' => $message->id,
                'locked_by' => $message->lockedBy,
            ]);
        }

        return $accepted;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intPayload(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('Invalid outbox payload.');
        }

        return (int) $value;
    }

    private function redactError(Throwable $exception): string
    {
        // Never persist exception messages (may contain provider bodies / destinations).
        return 'delivery_failed:' . $exception::class;
    }

    private function backoffSeconds(int $attemptCount): int
    {
        $exp = (int) min($this->backoffCapSeconds, $this->backoffBaseSeconds * (2 ** max(0, $attemptCount - 1)));
        $jitter = random_int(0, max(1, (int) floor($exp * 0.2)));

        return min($this->backoffCapSeconds, $exp + $jitter);
    }
}
