<?php

declare(strict_types=1);

namespace Academy\Infrastructure\RBAC;

use Academy\Domain\RBAC\Role;
use Academy\Domain\RBAC\RoleRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoRoleRepository implements RoleRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findByKey(string $roleKey): ?Role
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT role_id, role_key, name, is_privileged FROM roles WHERE role_key = :key LIMIT 1',
        );
        $stmt->execute(['key' => $roleKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findById(int $roleId): ?Role
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT role_id, role_key, name, is_privileged FROM roles WHERE role_id = :id LIMIT 1',
        );
        $stmt->execute(['id' => $roleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function userHasActiveRole(int $userId, string $roleKey): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = :user_id
               AND r.role_key = :role_key
               AND ur.current_marker = 1
               AND ur.revoked_at IS NULL
             LIMIT 1',
        );
        $stmt->execute([
            'user_id' => $userId,
            'role_key' => $roleKey,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Role
    {
        return new Role(
            roleId: (int) $row['role_id'],
            roleKey: (string) $row['role_key'],
            name: (string) $row['name'],
            isPrivileged: (bool) $row['is_privileged'],
        );
    }
}
