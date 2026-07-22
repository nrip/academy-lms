<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

final class IdentityNotificationEventTypes
{
    public const EMAIL_VERIFICATION_SEND = 'identity.email_verification.send';
    public const PASSWORD_RESET_SEND = 'identity.password_reset.send';
    public const MOBILE_OTP_SEND = 'identity.mobile_otp.send';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::EMAIL_VERIFICATION_SEND,
            self::PASSWORD_RESET_SEND,
            self::MOBILE_OTP_SEND,
        ];
    }
}
