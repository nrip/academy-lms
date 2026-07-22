<?php

declare(strict_types=1);

namespace Academy\Domain\Storage;

final class ObjectMetadata
{
    public function __construct(
        public readonly int $sizeBytes,
        public readonly ?string $mimeType,
        public readonly ?string $checksumSha256 = null,
    ) {
    }
}
