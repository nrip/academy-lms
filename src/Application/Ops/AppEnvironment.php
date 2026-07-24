<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

/**
 * Supported application environment names (RC-01).
 * Prefer EnvironmentCapability for capability decisions — do not scatter string checks.
 */
enum AppEnvironment: string
{
    case Local = 'local';
    case Testing = 'testing';
    case Ci = 'ci';
    case Uat = 'uat';
    case Staging = 'staging';
    case Production = 'production';

    public static function tryFromMixed(mixed $value): ?self
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public static function fromMixed(mixed $value, self $default = self::Local): self
    {
        return self::tryFromMixed($value) ?? $default;
    }
}
