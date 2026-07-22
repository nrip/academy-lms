<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Notifications\ProviderReceipt;
use Academy\Domain\Notifications\SmsDeliveryMessage;
use Academy\Domain\Notifications\SmsOtpDeliveryPort;

final class UnavailableSmsAdapter implements SmsOtpDeliveryPort
{
    public function send(SmsDeliveryMessage $message): ProviderReceipt
    {
        throw new ExternalServiceException('SMS delivery is not configured.');
    }
}
