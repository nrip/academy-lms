<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

/**
 * Failure categories for notification_deliveries.failure_category.
 */
final class NotificationFailureCategory
{
    public const INVALID_RECIPIENT = 'invalid_recipient';
    public const MISSING_VERIFICATION = 'missing_verification';
    public const TEMPLATE_VIOLATION = 'template_violation';
    public const ACCOUNT_SUSPENDED = 'account_suspended';
    public const ACCOUNT_DELETED = 'account_deleted';
    public const PROVIDER_PERMANENT = 'provider_permanent';
    public const PROVIDER_TRANSIENT = 'provider_transient';
    public const TIMEOUT = 'timeout';
    public const RATE_LIMITED = 'rate_limited';
    public const NETWORK = 'network';
    public const CONTEXT_MISSING = 'context_missing';

    /** @return list<string> */
    public static function terminal(): array
    {
        return [
            self::INVALID_RECIPIENT,
            self::MISSING_VERIFICATION,
            self::TEMPLATE_VIOLATION,
            self::ACCOUNT_SUSPENDED,
            self::ACCOUNT_DELETED,
            self::PROVIDER_PERMANENT,
            self::CONTEXT_MISSING,
        ];
    }

    /** @return list<string> */
    public static function retryable(): array
    {
        return [
            self::PROVIDER_TRANSIENT,
            self::TIMEOUT,
            self::RATE_LIMITED,
            self::NETWORK,
        ];
    }

    public static function isRetryable(string $category): bool
    {
        return in_array($category, self::retryable(), true);
    }
}
