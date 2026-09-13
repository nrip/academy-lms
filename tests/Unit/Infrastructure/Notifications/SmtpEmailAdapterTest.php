<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Notifications;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Infrastructure\Notifications\SmtpEmailAdapter;
use PHPUnit\Framework\TestCase;

final class SmtpEmailAdapterTest extends TestCase
{
    public function testConstructorRequiresHostAndFromAddress(): void
    {
        $this->expectException(ExternalServiceException::class);
        new SmtpEmailAdapter(
            host: '',
            port: 587,
            username: 'u',
            password: 'p',
            fromAddress: '',
            fromName: 'Academy',
            encryption: 'tls',
        );
    }

    public function testConstructorRejectsInvalidEncryption(): void
    {
        $this->expectException(ExternalServiceException::class);
        new SmtpEmailAdapter(
            host: 'smtp.example.test',
            port: 587,
            username: 'u',
            password: 'p',
            fromAddress: 'noreply@example.test',
            fromName: 'Academy',
            encryption: 'starttls',
        );
    }

    public function testSendRejectsInvalidRecipientWithoutConnecting(): void
    {
        $adapter = new SmtpEmailAdapter(
            host: 'smtp.example.test',
            port: 587,
            username: 'u',
            password: 'super-secret-smtp-password',
            fromAddress: 'noreply@example.test',
            fromName: 'Academy',
            encryption: 'none',
            socketFactory: static function (): never {
                self::fail('Socket must not be opened for invalid recipients.');
            },
        );

        try {
            $adapter->send(new EmailDeliveryMessage(
                toAddress: 'not-an-email',
                templateKey: 'certificate_issued',
                subject: 'x',
                bodyText: 'y',
                idempotencyKey: 'k1',
            ));
            self::fail('Expected ExternalServiceException');
        } catch (ExternalServiceException $e) {
            self::assertStringContainsString('recipient', strtolower($e->getMessage()));
            self::assertStringNotContainsString('super-secret-smtp-password', $e->getMessage());
        }
    }
}
