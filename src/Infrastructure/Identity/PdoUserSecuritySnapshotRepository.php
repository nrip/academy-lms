<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\AuthVersion;
use Academy\Domain\Identity\UserSecuritySnapshot;
use Academy\Domain\Identity\UserSecuritySnapshotRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoUserSecuritySnapshotRepository implements UserSecuritySnapshotRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $userId): ?UserSecuritySnapshot
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT
                u.user_id,
                u.account_status,
                u.locked_until,
                u.auth_version,
                EXISTS (
                    SELECT 1
                    FROM user_roles ur
                    INNER JOIN roles r ON r.role_id = ur.role_id
                    WHERE ur.user_id = u.user_id
                      AND ur.current_marker = 1
                      AND ur.revoked_at IS NULL
                      AND r.is_privileged = 1
                ) AS has_privileged_role
             FROM users u
             WHERE u.user_id = :user_id
             LIMIT 1',
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new UserSecuritySnapshot(
            userId: (int) $row['user_id'],
            accountStatus: (string) $row['account_status'],
            lockedUntil: $row['locked_until'] !== null
                ? new \DateTimeImmutable((string) $row['locked_until'], new \DateTimeZone('UTC'))
                : null,
            authVersion: AuthVersion::fromDatabase($row['auth_version']),
            hasPrivilegedRole: (bool) $row['has_privileged_role'],
        );
    }
}
