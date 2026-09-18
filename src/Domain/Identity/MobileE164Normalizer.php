<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;

/**
 * Normalizes Indian 10-digit or full E.164 mobiles for storage, HMAC, and rate-limit dimensions.
 */
final class MobileE164Normalizer
{
    public static function normalize(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            throw self::invalid();
        }

        // Reject alpha (includes extension markers like x), hash, and ;ext-style suffixes.
        if (preg_match('/[a-zA-Z#;]/', $trimmed) === 1) {
            throw self::invalid();
        }

        if (preg_match('/^\d{10}$/', $trimmed) === 1) {
            return '+91' . $trimmed;
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $trimmed) === 1) {
            return $trimmed;
        }

        throw self::invalid();
    }

    private static function invalid(): ValidationException
    {
        return new ValidationException('Please check the details below and try again.', [
            'mobile' => ['Enter a valid mobile number with country code (for example +91…), or a 10-digit Indian mobile.'],
        ]);
    }
}
