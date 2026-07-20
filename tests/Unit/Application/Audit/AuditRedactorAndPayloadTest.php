<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Audit;

use Academy\Application\Audit\AuditRedactor;
use Academy\Domain\Audit\SecurityAuditPayload;
use PHPUnit\Framework\TestCase;

final class AuditRedactorAndPayloadTest extends TestCase
{
    public function testRedactsNestedAndDifferentlyCasedSensitiveFields(): void
    {
        $redactor = new AuditRedactor();
        $result = $redactor->redact([
            'status' => 'ok',
            'Password' => 'secret',
            'nested' => [
                'OTP' => '123456',
                'csrf_token' => 'abc',
                'safe' => 1,
            ],
            'session_token' => 'raw',
        ]);

        self::assertSame('ok', $result['status']);
        self::assertSame('[REDACTED]', $result['Password']);
        self::assertSame('[REDACTED]', $result['nested']['OTP']);
        self::assertSame('[REDACTED]', $result['nested']['csrf_token']);
        self::assertSame(1, $result['nested']['safe']);
        self::assertSame('[REDACTED]', $result['session_token']);
    }

    public function testTypedPayloadRejectsUnexpectedFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityAuditPayload(
            'security.test',
            'session',
            '1',
            next: ['password' => 'nope'],
        );
    }

    public function testTypedPayloadAcceptsAllowListedFields(): void
    {
        $payload = new SecurityAuditPayload(
            'security.session_revoked',
            'session',
            '42',
            next: ['revoked' => 1, 'session_id' => 42],
        );
        self::assertSame(['revoked' => 1, 'session_id' => 42], $payload->newValue());
    }
}
