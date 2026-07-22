<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\ProviderReceipt;

final class RecordingEmailAdapter implements EmailDeliveryPort
{
    /** @var list<EmailDeliveryMessage> */
    private array $messages = [];

    public function send(EmailDeliveryMessage $message): ProviderReceipt
    {
        $this->messages[] = $message;

        return new ProviderReceipt('rec-email-' . count($this->messages));
    }

    /**
     * @return list<EmailDeliveryMessage>
     */
    public function recorded(): array
    {
        return $this->messages;
    }
}
