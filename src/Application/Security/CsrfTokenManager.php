<?php

declare(strict_types=1);

namespace Academy\Application\Security;

final class CsrfTokenManager
{
    public function generateRawToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function validate(string $rawToken, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawToken));
    }
}
