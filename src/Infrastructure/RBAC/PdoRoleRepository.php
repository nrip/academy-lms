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
