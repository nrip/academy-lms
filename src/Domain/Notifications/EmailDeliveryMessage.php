<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

final class EmailDeliveryMessage
{
    public function __construct(
        public readonly string $toAddress,
        public readonly string $templateKey,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly string $idempotencyKey,
    ) {
    }
}
