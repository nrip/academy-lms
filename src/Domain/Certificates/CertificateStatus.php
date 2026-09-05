<?php

declare(strict_types=1);

namespace Academy\Domain\Certificates;

final class CertificateStatus
{
    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::REVOKED];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid certificate status.');
        }
    }
}
