<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Audit;

use Academy\Domain\Audit\PaymentAuditPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentAuditPayloadTest extends TestCase
{
    public function testAllowListedFieldsAreAccepted(): void
    {
        $payload = new PaymentAuditPayload(
            action: 'payment.attempt_created',
            entityType: 'payment',
            entityId: '42',
            next: [
                'user_id' => 7,
                'application_id' => 3,
                'payment_id' => 42,
                'public_reference' => 'PAY-3-01-ABCD',
                'provider' => 'razorpay',
                'provider_order_id' => 'order_fake_1',
                'amount_minor' => 1180000,
                'currency' => 'INR',
                'status' => 'pending',
                'attempt_number' => 1,
                'row_version' => 2,
                'failure_category' => null,
                'result' => 'ok',
            ],
        );

        self::assertSame('payment.attempt_created', $payload->action());
        self::assertSame('payment', $payload->affectedEntityType());
        self::assertSame('42', $payload->affectedEntityId());
        self::assertNotNull($payload->newValue());
        self::assertNull($payload->previousValue());
    }

    #[DataProvider('nonAllowListedFields')]
    public function testNonAllowListedFieldsAreRejected(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentAuditPayload(
            action: 'payment.attempt_created',
            entityType: 'payment',
            entityId: '42',
            next: [$field => 'value'],
        );
    }

    public function testDisallowsSecretFieldsInPreviousValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentAuditPayload(
            action: 'payment.attempt_created',
            entityType: 'payment',
            entityId: '42',
            previous: ['razorpay_key_secret' => 'secret'],
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonAllowListedFields(): array
    {
        return [
            ['razorpay_key_secret'],
            ['key_secret'],
            ['object_key'],
            ['signed_document_url'],
            ['first_name'],
            ['card_number'],
        ];
    }
}
