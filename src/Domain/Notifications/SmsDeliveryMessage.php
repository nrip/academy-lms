<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

final class SmsDeliveryMessage
{
    public function __construct(
        public readonly string $toE164,
        public readonly string $templateKey,
        public readonly string $bodyText,
        public readonly string $idempotencyKey,
    ) {
    }
}
