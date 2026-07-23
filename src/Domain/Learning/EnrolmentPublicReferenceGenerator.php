<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class EnrolmentPublicReferenceGenerator
{
    public function generate(int $applicationId, DateTimeImmutable $at): string
    {
        $stamp = $at->format('YmdHis');

        return sprintf('ENR-%s-%d-%s', $stamp, $applicationId, bin2hex(random_bytes(3)));
    }
}
