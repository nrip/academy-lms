<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Notifications\ProviderReceipt;
use Academy\Domain\Notifications\SmsDeliveryMessage;
use Academy\Domain\Notifications\SmsOtpDeliveryPort;

final class RecordingSmsAdapter implements SmsOtpDeliveryPort
{
    /** @var list<SmsDeliveryMessage> */
    private array $messages = [];

    public function send(SmsDeliveryMessage $message): ProviderReceipt
    {
        $this->messages[] = $message;

        return new ProviderReceipt('rec-sms-' . count($this->messages));
    }

    /**
     * @return list<SmsDeliveryMessage>
     */
    public function recorded(): array
    {
        return $this->messages;
    }
}
