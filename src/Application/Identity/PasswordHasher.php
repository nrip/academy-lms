<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
}
