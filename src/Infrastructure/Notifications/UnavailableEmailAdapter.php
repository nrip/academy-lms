<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\ProviderReceipt;

final class UnavailableEmailAdapter implements EmailDeliveryPort
{
    public function send(EmailDeliveryMessage $message): ProviderReceipt
    {
        throw new ExternalServiceException('Email delivery is not configured.');
    }
}
