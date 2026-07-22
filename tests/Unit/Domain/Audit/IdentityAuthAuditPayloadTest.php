<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Audit;

use Academy\Domain\Audit\IdentityAuthAuditPayload;
use PHPUnit\Framework\TestCase;

final class IdentityAuthAuditPayloadTest extends TestCase
{
    public function testAllowListedFieldsAccepted(): void
    {
        $payload = new IdentityAuthAuditPayload(
            'identity.login_succeeded',
            'user',
            '1',
            next: [
                'user_id' => 1,
                'result' => 'succeeded',
                'auth_version_before' => 1,
                'auth_version_after' => 1,
            ],
        );
        self::assertSame('identity.login_succeeded', $payload->action());
    }

    public function testDisallowedFieldRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IdentityAuthAuditPayload(
            'identity.login_failed',
            'user',
            '1',
            next: ['email' => 'secret@example.test'],
        );
    }
}
