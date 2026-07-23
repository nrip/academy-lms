<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

final class PaymentProvider
{
    public const RAZORPAY = 'razorpay';

    /** @var list<string> */
    public const ALL = [self::RAZORPAY];

    public static function assertValid(string $provider): void
    {
        if (!in_array($provider, self::ALL, true)) {
            throw new \InvalidArgumentException('Unsupported payment provider.');
        }
    }
}
