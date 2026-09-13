<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Notifications;

use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Infrastructure\Notifications\AcademyEmailMime;
use PHPUnit\Framework\TestCase;

final class AcademyEmailMimeTest extends TestCase
{
    public function testHtmlIsAnAlternativePartNotASecondMessage(): void
    {
        $payload = AcademyEmailMime::data(
            'Northwind Academy',
            'noreply@example.test',
            'ada@example.test',
            new EmailDeliveryMessage(
                toAddress: 'ada@example.test',
                templateKey: 'email_verify',
                subject: 'Verify your Academy account',
                bodyText: "Welcome.\n\nVerify email\nhttps://learn.example.test/verify-email?token=abc",
                idempotencyKey: 'id-1',
                bodyHtml: '<p>Welcome.</p><a href="https://learn.example.test/verify-email?token=abc">Verify email</a>',
            ),
        );

        self::assertStringContainsString('multipart/alternative', $payload);
        self::assertStringContainsString('Content-Type: text/plain', $payload);
        self::assertStringContainsString('Content-Type: text/html', $payload);
        self::assertSame(1, substr_count($payload, 'Subject: Verify your Academy account'));
    }
}
