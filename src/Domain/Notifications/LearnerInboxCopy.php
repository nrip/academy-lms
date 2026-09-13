<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

/**
 * Learner-safe snapshot written when a transactional email is actually sent.
 * Must not carry a recipient address, verification token, or storage key.
 */
final class LearnerInboxCopy
{
    public function __construct(
        public readonly int $userId,
        public readonly int $outboxMessageId,
        public readonly string $eventType,
        public readonly string $title,
        public readonly string $body,
        public readonly string $href,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     */
    public static function fromRender(
        int $userId,
        int $outboxMessageId,
        string $eventType,
        string $subject,
        string $body,
        array $variables,
    ): self {
        return new self(
            $userId,
            $outboxMessageId,
            $eventType,
            self::clip($subject, 255, 'Update'),
            self::clip($body, 8000, ''),
            InAppNotificationHref::fromVariables($eventType, $variables),
        );
    }

    private static function clip(string $value, int $max, string $fallback): string
    {
        $value = trim(str_replace("\0", '', $value));
        if ($value === '') {
            return $fallback;
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
