<?php

declare(strict_types=1);

namespace Academy\Infrastructure\RBAC;

use Academy\Domain\RBAC\PermissionRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoPermissionRepository implements PermissionRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function permissionKeysForUser(int $userId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT p.permission_key
             FROM user_roles ur
             INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE ur.user_id = :user_id
               AND ur.current_marker = 1
               AND ur.revoked_at IS NULL
             ORDER BY p.permission_key ASC',
        );
        $stmt->execute(['user_id' => $userId]);
        /** @var list<string> $keys */
        $keys = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $keys[] = (string) $row['permission_key'];
        }

        return $keys;
    }

    public function permissionKeysForRoleKey(string $roleKey): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT p.permission_key
             FROM roles r
             INNER JOIN role_permissions rp ON rp.role_id = r.role_id
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE r.role_key = :role_key
             ORDER BY p.permission_key ASC',
        );
        $stmt->execute(['role_key' => $roleKey]);
        /** @var list<string> $keys */
        $keys = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $keys[] = (string) $row['permission_key'];
        }

        return $keys;
    }
}
