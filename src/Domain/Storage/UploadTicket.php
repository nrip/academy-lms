<?php

declare(strict_types=1);

namespace Academy\Domain\Storage;

use DateTimeImmutable;

final class UploadTicket
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $uploadUrl,
        public readonly string $method,
        public readonly array $headers,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }
}
