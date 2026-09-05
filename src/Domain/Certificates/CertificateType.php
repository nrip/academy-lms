<?php

declare(strict_types=1);

namespace Academy\Domain\Certificates;

final class CertificateType
{
    public const COMPLETION = 'completion';

    /** @var list<string> */
    public const ALL = [self::COMPLETION];

    public static function assertValid(string $type): void
    {
        if (!in_array($type, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid certificate type.');
        }
    }
}
