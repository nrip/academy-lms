<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

/**
 * Parses MySQL BIGINT UNSIGNED auth_version into a PHP int without float coercion.
 *
 * Design: column is BIGINT UNSIGNED for forward headroom; application ceiling is
 * exactly PHP_INT_MAX so PHP never accepts values that would become floats or
 * exceed signed 64-bit integers.
 */
final class AuthVersion
{
    public const CEILING = PHP_INT_MAX; // 9223372036854775807

    public static function fromDatabase(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 1 || $value > self::CEILING) {
                throw new \DomainException('auth_version out of application range.');
            }

            return $value;
        }

        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            throw new \DomainException('auth_version is not a safe unsigned integer string.');
        }

        $ceiling = (string) self::CEILING;
        if (strlen($value) > strlen($ceiling) || (strlen($value) === strlen($ceiling) && $value > $ceiling)) {
            throw new \DomainException('auth_version exceeds PHP_INT_MAX application ceiling.');
        }

        $parsed = (int) $value;
        if ($parsed < 1) {
            throw new \DomainException('auth_version must be >= 1.');
        }

        return $parsed;
    }
}
