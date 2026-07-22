<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

interface EmailDeliveryPort
{
    public function send(EmailDeliveryMessage $message): ProviderReceipt;
}
