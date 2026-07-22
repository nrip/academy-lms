<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Defence-in-depth redaction for Monolog context/extra. Does not close access-log leakage
 * at web server / reverse proxy / CDN / APM layers — see ops prerequisite artifact.
 */
final class SensitiveDataProcessor implements ProcessorInterface
{
    /** @var list<string> */
    private const DENY_KEYS = [
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
        'delivery_ciphertext',
        'ciphertext',
        'nonce',
        'pepper',
        'confirmation',
        'provider_response',
        'provider_body',
        'raw_token',
        'link_token',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->walk($record->context);
        $extra = $this->walk($record->extra);
        $message = $this->scrubString($record->message);

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    /**
     * @param array<mixed> $payload
     * @return array<mixed>
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
                $out[$key] = $this->walk($value);
                continue;
            }
            if (is_string($value)) {
                $out[$key] = $this->scrubString($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function scrubString(string $value): string
    {
        $scrubbed = preg_replace(
            '/([?&](?:token|otp|confirmation|confirm)=)[^&\s#]+/i',
            '$1[REDACTED]',
            $value,
        );

        return is_string($scrubbed) ? $scrubbed : '[REDACTED]';
    }

    private function isDenied(string $normalizedKey): bool
    {
        foreach (self::DENY_KEYS as $denied) {
            if (
                $normalizedKey === $denied
                || str_ends_with($normalizedKey, '_' . $denied)
                || str_contains($normalizedKey, $denied)
            ) {
                return true;
            }
        }

        return false;
    }
}
