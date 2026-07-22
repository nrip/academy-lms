<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

interface SmsOtpDeliveryPort
{
    public function send(SmsDeliveryMessage $message): ProviderReceipt;
}
