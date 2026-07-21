<?php

declare(strict_types=1);

namespace Academy\Domain\RBAC;

interface PermissionRepository
{
    /**
     * Union of permission keys across all active roles for the user.
     *
     * @return list<string>
     * @throws \Throwable on store failure — map to 503
     */
    public function permissionKeysForUser(int $userId): array;

    /**
     * @return list<string>
     * @throws \Throwable on store failure — map to 503
     */
    public function permissionKeysForRoleKey(string $roleKey): array;
}
