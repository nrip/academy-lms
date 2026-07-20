<?php

declare(strict_types=1);

namespace Academy\Application\Audit;

/**
 * Defence-in-depth redaction for nested / differently cased sensitive keys.
 */
final class AuditRedactor
{
    /** @var list<string> */
    private const DENY = [
        'password',
        'password_hash',
        'otp',
        'totp_secret',
        'recovery_code',
        'reset_token',
        'session_token',
        'csrf_token',
        'csrf',
        'authorization',
        'cookie',
        'secret',
        'token',
    ];

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    public function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->walk($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function walk(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));
            if ($this->isDenied($normalized)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$key] = $this->walk($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function isDenied(string $normalizedKey): bool
    {
        foreach (self::DENY as $denied) {
            if ($normalizedKey === $denied || str_ends_with($normalizedKey, '_' . $denied) || str_contains($normalizedKey, $denied)) {
                return true;
            }
        }

        return false;
    }
}
