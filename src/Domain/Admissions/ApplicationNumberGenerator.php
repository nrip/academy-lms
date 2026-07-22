<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

/**
 * Generates a stable non-sensitive public application number.
 * Not derived solely from the sequential primary key.
 */
final class ApplicationNumberGenerator
{
    public function generate(): string
    {
        $entropy = strtoupper(bin2hex(random_bytes(5)));

        return 'APP-' . gmdate('ymd') . '-' . $entropy;
    }
}
