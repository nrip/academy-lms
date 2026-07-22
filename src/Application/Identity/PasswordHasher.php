<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

/**
 * Argon2id password hashing with verify / needs-rehash helpers.
 * Never log password or hash material.
 */
final class PasswordHasher
{
    /**
     * Precomputed Argon2id hash of a fixed dummy password for unknown-email timing padding.
     * Must remain a valid PASSWORD_ARGON2ID hash so password_verify always runs crypto.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$bHRCWHBmb1MwS0Fwd0N3Zg$Ekb1vZcQMNef3CK4/RGf70bumb39X8fUxeHxEjBFBKA';

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    /**
     * Always executes password_verify against a valid Argon2id hash (discard result).
     */
    public function verifyDummy(string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    public function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }
}
