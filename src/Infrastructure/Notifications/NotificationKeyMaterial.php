<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use InvalidArgumentException;

/**
 * Strict base64-decoded notification delivery keys with versioned rotation.
 * Never expose key material in logs or exception messages.
 */
final class NotificationKeyMaterial
{
    private readonly string $currentKey;
    private readonly ?string $previousKey;

    public function __construct(
        string $currentKeyBase64,
        private readonly int $currentVersion,
        ?string $previousKeyBase64 = null,
        private readonly ?int $previousVersion = null,
    ) {
        if ($currentVersion < 1) {
            throw new InvalidArgumentException('NOTIFICATION_DELIVERY_KEY_VERSION must be a positive SMALLINT.');
        }

        $this->currentKey = self::decodeStrict32($currentKeyBase64);
        if ($previousKeyBase64 === null || $previousKeyBase64 === '') {
            $this->previousKey = null;
            if ($this->previousVersion !== null) {
                throw new InvalidArgumentException('Previous key version requires a previous key.');
            }
        } else {
            $this->previousKey = self::decodeStrict32($previousKeyBase64);
            if ($this->previousVersion === null || $this->previousVersion < 1) {
                throw new InvalidArgumentException('Previous notification delivery key version must be a positive SMALLINT.');
            }
            if ($this->previousVersion === $this->currentVersion) {
                throw new InvalidArgumentException('Previous and current notification delivery key versions must differ.');
            }
        }
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    public function currentKey(): string
    {
        return $this->currentKey;
    }

    public function keyForVersion(int $version): string
    {
        if ($version === $this->currentVersion) {
            return $this->currentKey;
        }
        if ($this->previousKey !== null && $this->previousVersion === $version) {
            return $this->previousKey;
        }

        throw new InvalidArgumentException('Unknown notification delivery key version.');
    }

    private static function decodeStrict32(string $base64): string
    {
        if ($base64 === '') {
            throw new InvalidArgumentException('Notification delivery key must be valid base64.');
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Notification delivery key must be valid base64.');
        }

        // Reject non-canonical encodings (strict round-trip with standard padding).
        if (base64_encode($decoded) !== $base64) {
            throw new InvalidArgumentException('Notification delivery key must be valid base64.');
        }

        if (strlen($decoded) !== 32) {
            throw new InvalidArgumentException('Notification delivery key must decode to exactly 32 bytes.');
        }

        return $decoded;
    }
}
