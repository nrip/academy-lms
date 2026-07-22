<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

/**
 * Runtime flags for whether email / SMS delivery adapters are configured.
 * Issue/resend HTTP paths may return 503 when the needed channel is false.
 */
final class NotificationCapability
{
    public function __construct(
        public readonly bool $emailConfigured,
        public readonly bool $smsConfigured,
    ) {
    }

    public static function fromEnvFlags(bool $emailConfigured, bool $smsConfigured): self
    {
        return new self($emailConfigured, $smsConfigured);
    }

    public function canSendEmail(): bool
    {
        return $this->emailConfigured;
    }

    public function canSendSms(): bool
    {
        return $this->smsConfigured;
    }
}
