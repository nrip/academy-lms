<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

final class SealedSecret
{
    public function __construct(
        public readonly string $ciphertext,
        public readonly string $nonce,
        public readonly int $keyVersion,
    ) {
    }
}
