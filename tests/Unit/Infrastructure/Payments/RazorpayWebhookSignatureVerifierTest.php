<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Payments;

use Academy\Infrastructure\Payments\RazorpayWebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class RazorpayWebhookSignatureVerifierTest extends TestCase
{
    public function testValidSignaturePasses(): void
    {
        $secret = 'whsec_test';
        $body = '{"id":"evt_1","event":"payment.captured"}';
        $signature = hash_hmac('sha256', $body, $secret);
        $verifier = new RazorpayWebhookSignatureVerifier($secret);

        self::assertTrue($verifier->verify($body, $signature));
    }

    public function testTamperedBodyFails(): void
    {
        $secret = 'whsec_test';
        $body = '{"id":"evt_1","event":"payment.captured"}';
        $signature = hash_hmac('sha256', $body, $secret);
        $verifier = new RazorpayWebhookSignatureVerifier($secret);

        self::assertFalse($verifier->verify($body . 'x', $signature));
    }
}
