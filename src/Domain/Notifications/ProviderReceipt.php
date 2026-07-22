<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

final class ProviderReceipt
{
    public function __construct(
        public readonly ?string $providerMessageId,
    ) {
    }
}
