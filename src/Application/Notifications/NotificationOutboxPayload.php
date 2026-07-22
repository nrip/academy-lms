<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;

/**
 * Allow-listed outbox payloads for identity notification events.
 * Never include destinations, tokens, OTPs or sealed ciphertext.
 */
final class NotificationOutboxPayload
{
    /**
     * @return array{verification_token_id: int, purpose: string}
     */
    public static function forEmailVerification(int $verificationTokenId): array
    {
        return self::forToken($verificationTokenId, TokenPurpose::EMAIL_VERIFY);
    }

    /**
     * @return array{verification_token_id: int, purpose: string}
     */
    public static function forPasswordReset(int $verificationTokenId): array
    {
        return self::forToken($verificationTokenId, TokenPurpose::PASSWORD_RESET);
    }

    /**
     * @return array{verification_token_id: int, purpose: string}
     */
    public static function forToken(int $verificationTokenId, string $purpose): array
    {
        TokenPurpose::assertValid($purpose);
        if ($verificationTokenId < 1) {
            throw new ValidationException('Invalid verification token id.');
        }

        $payload = [
            'verification_token_id' => $verificationTokenId,
            'purpose' => $purpose,
        ];
        self::assertAllowListed(
            $purpose === TokenPurpose::PASSWORD_RESET
                ? IdentityNotificationEventTypes::PASSWORD_RESET_SEND
                : IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND,
            $payload,
        );

        return $payload;
    }

    /**
     * @return array{verification_challenge_id: int, channel: string}
     */
    public static function forMobileOtp(int $verificationChallengeId, string $channel = 'sms'): array
    {
        if ($verificationChallengeId < 1) {
            throw new ValidationException('Invalid verification challenge id.');
        }
        if ($channel !== 'sms') {
            throw new ValidationException('Invalid challenge channel.');
        }

        $payload = [
            'verification_challenge_id' => $verificationChallengeId,
            'channel' => $channel,
        ];
        self::assertAllowListed(IdentityNotificationEventTypes::MOBILE_OTP_SEND, $payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function assertAllowListed(string $eventType, array $payload): void
    {
        $allowed = match ($eventType) {
            IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND,
            IdentityNotificationEventTypes::PASSWORD_RESET_SEND => [
                'verification_token_id' => true,
                'purpose' => true,
            ],
            IdentityNotificationEventTypes::MOBILE_OTP_SEND => [
                'verification_challenge_id' => true,
                'channel' => true,
            ],
            default => throw new ValidationException('Unsupported notification outbox event type.'),
        };

        foreach (array_keys($payload) as $key) {
            if (!isset($allowed[$key])) {
                throw new ValidationException('Outbox payload key is not allow-listed.');
            }
        }

        foreach (array_keys($allowed) as $required) {
            if (!array_key_exists($required, $payload)) {
                throw new ValidationException('Outbox payload is missing a required key.');
            }
        }
    }
}
