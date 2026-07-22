<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\RbacAuditPayload;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\AuthVersion;
use Academy\Domain\RBAC\RoleKeys;
use PDO;

/**
 * Binds the initial Applicant role during registration only.
 *
 * Must be called only from RegistrationService inside the registration ambient transaction.
 * Does not increment auth_version and does not revoke sessions.
 */
final class InitialApplicantRoleBinder
{
    /**
     * @return int user_role_id
     */
    public function bind(PDO $pdo, int $userId, \DateTimeImmutable $now, AuditService $audit): int
    {
        $roleStmt = $pdo->prepare(
            'SELECT role_id, role_key FROM roles WHERE role_key = :role_key LIMIT 1',
        );
        $roleStmt->execute(['role_key' => RoleKeys::APPLICANT]);
        $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($role === false) {
            throw new NotFoundException('Applicant role is not configured.');
        }

        $roleId = (int) $role['role_id'];
        $roleKey = (string) $role['role_key'];

        $userStmt = $pdo->prepare(
            'SELECT auth_version FROM users WHERE user_id = :user_id LIMIT 1',
        );
        $userStmt->execute(['user_id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new NotFoundException('User not found.');
        }
        $authVersion = AuthVersion::fromDatabase($user['auth_version']);

        $nowStr = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $insert = $pdo->prepare(
            'INSERT INTO user_roles (
                user_id, role_id, assigned_by, assigned_at, revoked_at, revoked_by,
                revocation_reason, current_marker, created_at, updated_at
            ) VALUES (
                :user_id, :role_id, NULL, :assigned_at, NULL, NULL,
                NULL, 1, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => $nowStr,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        $userRoleId = (int) $pdo->lastInsertId();

        $audit->record(
            new RbacAuditPayload(
                action: 'rbac.role.assign',
                entityType: 'user_role',
                entityId: (string) $userRoleId,
                previous: [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'role_key' => $roleKey,
                    'auth_version' => $authVersion,
                    'current_marker' => null,
                    'user_role_id' => null,
                    'assigned_by' => null,
                ],
                next: [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'role_key' => $roleKey,
                    'auth_version' => $authVersion,
                    'current_marker' => 1,
                    'user_role_id' => $userRoleId,
                    'assigned_by' => null,
                ],
                reason: 'registration',
            ),
            actorType: 'system',
            actorUserId: null,
            source: 'registration',
        );

        return $userRoleId;
    }
}
