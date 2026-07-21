<?php

declare(strict_types=1);

namespace Academy\Domain\RBAC;

final class Role
{
    public function __construct(
        public readonly int $roleId,
        public readonly string $roleKey,
        public readonly string $name,
        public readonly bool $isPrivileged,
    ) {
    }
}
