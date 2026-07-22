<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Audit;

use Academy\Domain\Audit\IdentityTokenAuditPayload;
use Academy\Domain\Audit\NotificationAuditPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentityNotificationAuditPayloadTest extends TestCase
{
    public function testIdentityAllowListAcceptsKnownFields(): void
    {
        $payload = new IdentityTokenAuditPayload(
            'identity.token_context_created',
            'token_confirmation_context',
            '1',
            next: [
                'context_id' => 1,
                'verification_token_id' => 2,
                'user_id' => 3,
                'purpose' => 'email_verify',
            ],
        );

        self::assertSame(1, $payload->newValue()['context_id']);
    }

    public function testIdentityRejectsCiphertextField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityTokenAuditPayload(
            'identity.token_context_created',
            'token_confirmation_context',
            '1',
            next: ['delivery_ciphertext' => 'nope'],
        );
    }

    public function testIdentityRejectsTokenField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityTokenAuditPayload(
            'identity.token_context_created',
            'token_confirmation_context',
            '1',
            next: ['raw_token' => 'abcdef'],
        );
    }

    public function testNotificationRejectsSecretFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NotificationAuditPayload(
            'notification.delivery_succeeded',
            'verification_token',
            '1',
            next: ['ciphertext' => 'secret'],
        );
    }

    public function testNotificationAcceptsAllowListedFields(): void
    {
        $payload = new NotificationAuditPayload(
            'notification.delivery_succeeded',
            'verification_token',
            '9',
            next: [
                'record_id' => 9,
                'purpose_or_channel' => 'email_verify',
                'outbox_message_id' => 3,
                'provider_message_id' => 'prov-1',
            ],
        );

        self::assertSame(9, $payload->newValue()['record_id']);
    }
}
