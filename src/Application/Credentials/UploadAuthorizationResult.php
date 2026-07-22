<?php

declare(strict_types=1);

namespace Academy\Application\Credentials;

use DateTimeImmutable;

final class UploadAuthorizationResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $authorizationId,
        public readonly int $requirementId,
        public readonly string $objectKey,
        public readonly string $uploadUrl,
        public readonly string $method,
        public readonly array $headers,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }
}
