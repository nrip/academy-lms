<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

/** Stable public payment reference (not the internal BIGINT). */
final class PaymentPublicReferenceGenerator
{
    public function generate(int $applicationId, int $attemptNumber): string
    {
        $entropy = bin2hex(random_bytes(4));

        return sprintf('PAY-%d-%02d-%s', $applicationId, $attemptNumber, strtoupper($entropy));
    }
}
