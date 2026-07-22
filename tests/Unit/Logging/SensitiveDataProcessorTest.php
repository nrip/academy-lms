<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Logging;

use Academy\Infrastructure\Logging\SensitiveDataProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    public function testRedactsDenyKeysInContext(): void
    {
        $processor = new SensitiveDataProcessor();
        $record = new LogRecord(
            new DateTimeImmutable('now'),
            'test',
            Level::Info,
            'safe message',
            [
                'token' => 'raw-secret-token',
                'otp' => '123456',
                'delivery_ciphertext' => 'cipher',
                'user_id' => 42,
            ],
        );

        $out = $processor($record);

        self::assertSame('[REDACTED]', $out->context['token']);
        self::assertSame('[REDACTED]', $out->context['otp']);
        self::assertSame('[REDACTED]', $out->context['delivery_ciphertext']);
        self::assertSame(42, $out->context['user_id']);
    }

    public function testRedactsTokenQueryInUriLikeStrings(): void
    {
        $processor = new SensitiveDataProcessor();
        $record = new LogRecord(
            new DateTimeImmutable('now'),
            'test',
            Level::Warning,
            'GET /verify-email?token=abcdef0123456789&x=1',
            [
                'uri' => 'https://example.test/verify-email?token=deadbeefcafebabe&otp=999999',
            ],
        );

        $out = $processor($record);

        self::assertStringContainsString('token=[REDACTED]', $out->message);
        self::assertStringNotContainsString('abcdef0123456789', $out->message);
        self::assertStringContainsString('token=[REDACTED]', $out->context['uri']);
        self::assertStringContainsString('otp=[REDACTED]', $out->context['uri']);
        self::assertStringNotContainsString('deadbeefcafebabe', $out->context['uri']);
        self::assertStringNotContainsString('999999', $out->context['uri']);
    }
}
