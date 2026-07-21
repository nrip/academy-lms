<?php

declare(strict_types=1);

namespace Academy\Domain\RBAC;

interface RoleRepository
{
    public function findByKey(string $roleKey): ?Role;

    public function findById(int $roleId): ?Role;
}
