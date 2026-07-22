<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\ProviderReceipt;
use RuntimeException;

final class LocalFileEmailAdapter implements EmailDeliveryPort
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function send(EmailDeliveryMessage $message): ProviderReceipt
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create local mail directory.');
        }

        $filename = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHisu')
            . '-' . bin2hex(random_bytes(8))
            . '.eml';
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $contents = "To: {$message->toAddress}\r\n"
            . "Subject: {$message->subject}\r\n"
            . "X-Template-Key: {$message->templateKey}\r\n"
            . "X-Idempotency-Key: {$message->idempotencyKey}\r\n"
            . "\r\n"
            . $message->bodyText;

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write local mail file.');
        }

        if (!chmod($path, 0600)) {
            throw new RuntimeException('Unable to set local mail file permissions.');
        }

        return new ProviderReceipt('local-file-' . $filename);
    }
}
