<?php

declare(strict_types=1);

namespace Academy\Application\RBAC;

use Academy\Application\Audit\AuditService;
use Academy\Application\Security\SessionService;
use Academy\Domain\Audit\RbacAuditPayload;
use Academy\Domain\Exception\AuthVersionCeilingException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\AuthVersion;
use Academy\Domain\RBAC\Role;
use Academy\Domain\RBAC\RoleRepository;
use Academy\Infrastructure\Database\TransactionManager;
use PDO;
use PDOException;
use Throwable;

/**
 * Role assign / revoke / reassign with ordered locking:
 * users FOR UPDATE → current user_roles FOR UPDATE → mutate → auth_version++ → audit → commit,
 * then best-effort SessionService::revokeAllForUser outside the domain transaction.
 */
final class RoleAssignmentService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly RoleRepository $roles,
        private readonly AuditService $audit,
        private readonly SessionService $sessions,
    ) {
    }

    public function assign(int $targetUserId, string $roleKey, ?int $actorUserId, ?string $reason = null): void
    {
        $role = $this->requireRole($roleKey);
        $this->mutate($targetUserId, $role, 'assign', $actorUserId, $reason);
    }

    public function revoke(int $targetUserId, string $roleKey, ?int $actorUserId, ?string $reason = null): void
    {
        $role = $this->requireRole($roleKey);
        $this->mutate($targetUserId, $role, 'revoke', $actorUserId, $reason);
    }

    /**
     * Reassign means assigning a role after an earlier historical assignment was revoked.
     *
     * It intentionally delegates to the same mutation path and audit action as assign
     * (`rbac.role.assign`): a new user_roles row is inserted with current_marker = 1.
     * The revoked historical row is never restored or edited.
     */
    public function reassign(int $targetUserId, string $roleKey, ?int $actorUserId, ?string $reason = null): void
    {
        $this->assign($targetUserId, $roleKey, $actorUserId, $reason);
    }

    private function requireRole(string $roleKey): Role
    {
        $roleKey = trim($roleKey);
        if ($roleKey === '') {
            throw new ValidationException('Role key is required.', ['role_key' => ['Role key is required.']]);
        }

        $role = $this->roles->findByKey($roleKey);
        if ($role === null) {
            throw new NotFoundException('Role not found.');
        }

        return $role;
    }

    private function mutate(
        int $targetUserId,
        Role $role,
        string $operation,
        ?int $actorUserId,
        ?string $reason,
    ): void {
        if ($targetUserId < 1) {
            throw new ValidationException('Target user is required.', ['user_id' => ['Target user is required.']]);
        }

        try {
            $this->transactions->run(function (PDO $pdo) use ($targetUserId, $role, $operation, $actorUserId, $reason): void {
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

                // 1. Lock users row first (never acquire user_roles before users).
                $userStmt = $pdo->prepare('SELECT user_id, auth_version FROM users WHERE user_id = :id FOR UPDATE');
                $userStmt->execute(['id' => $targetUserId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($user === false) {
                    throw new NotFoundException('User not found.');
                }
                $previousAuthVersion = AuthVersion::fromDatabase($user['auth_version']);

                // 2. Lock current user_roles row for this (user, role), if any.
                $currentStmt = $pdo->prepare(
                    'SELECT user_role_id, current_marker, revoked_at
                     FROM user_roles
                     WHERE user_id = :user_id AND role_id = :role_id AND current_marker = 1
                     FOR UPDATE',
                );
                $currentStmt->execute([
                    'user_id' => $targetUserId,
                    'role_id' => $role->roleId,
                ]);
                $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

                $previous = [
                    'user_id' => $targetUserId,
                    'role_id' => $role->roleId,
                    'role_key' => $role->roleKey,
                    'auth_version' => $previousAuthVersion,
                    'current_marker' => $current !== false ? 1 : null,
                    'user_role_id' => $current !== false ? (int) $current['user_role_id'] : null,
                ];

                // 3. Mutate role history (never DELETE historical rows).
                if ($operation === 'assign') {
                    if ($current !== false) {
                        throw new ConflictException('Role is already assigned.');
                    }
                    $insert = $pdo->prepare(
                        'INSERT INTO user_roles (
                            user_id, role_id, assigned_by, assigned_at, revoked_at, revoked_by,
                            revocation_reason, current_marker, created_at, updated_at
                        ) VALUES (
                            :user_id, :role_id, :assigned_by, :assigned_at, NULL, NULL,
                            NULL, 1, :created_at, :updated_at
                        )',
                    );
                    try {
                        $insert->execute([
                            'user_id' => $targetUserId,
                            'role_id' => $role->roleId,
                            'assigned_by' => $actorUserId,
                            'assigned_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } catch (PDOException $exception) {
                        if ($this->isDuplicateKey($exception)) {
                            throw new ConflictException('Role is already assigned.');
                        }
                        throw $exception;
                    }
                    $newUserRoleId = (int) $pdo->lastInsertId();
                    $action = 'rbac.role.assign';
                    $next = [
                        'user_id' => $targetUserId,
                        'role_id' => $role->roleId,
                        'role_key' => $role->roleKey,
                        'current_marker' => 1,
                        'user_role_id' => $newUserRoleId,
                        'assigned_by' => $actorUserId,
                    ];
                } elseif ($operation === 'revoke') {
                    if ($current === false) {
                        throw new ConflictException('Role is not currently assigned.');
                    }
                    $revoke = $pdo->prepare(
                        'UPDATE user_roles
                         SET revoked_at = :revoked_at,
                             revoked_by = :revoked_by,
                             revocation_reason = :reason,
                             current_marker = NULL,
                             updated_at = :updated_at
                         WHERE user_role_id = :id AND current_marker = 1',
                    );
                    $revoke->execute([
                        'revoked_at' => $now,
                        'revoked_by' => $actorUserId,
                        'reason' => $reason,
                        'updated_at' => $now,
                        'id' => (int) $current['user_role_id'],
                    ]);
                    if ($revoke->rowCount() !== 1) {
                        throw new ConflictException('Role revoke conflict.');
                    }
                    $action = 'rbac.role.revoke';
                    $next = [
                        'user_id' => $targetUserId,
                        'role_id' => $role->roleId,
                        'role_key' => $role->roleKey,
                        'current_marker' => null,
                        'user_role_id' => (int) $current['user_role_id'],
                        'revoked_by' => $actorUserId,
                    ];
                } else {
                    throw new ValidationException('Unsupported role mutation.', ['operation' => ['Unsupported role mutation.']]);
                }

                // 4. Guarded auth_version increment (application ceiling = PHP_INT_MAX).
                $increment = $pdo->prepare(
                    'UPDATE users
                     SET auth_version = auth_version + 1, updated_at = :updated_at
                     WHERE user_id = :id AND auth_version < :ceiling',
                );
                $increment->execute([
                    'updated_at' => $now,
                    'id' => $targetUserId,
                    'ceiling' => AuthVersion::CEILING,
                ]);
                if ($increment->rowCount() !== 1) {
                    $check = $pdo->prepare('SELECT auth_version FROM users WHERE user_id = :id');
                    $check->execute(['id' => $targetUserId]);
                    $still = $check->fetch(PDO::FETCH_ASSOC);
                    if ($still === false) {
                        throw new NotFoundException('User not found.');
                    }
                    throw new AuthVersionCeilingException();
                }

                $versionStmt = $pdo->prepare('SELECT auth_version FROM users WHERE user_id = :id');
                $versionStmt->execute(['id' => $targetUserId]);
                $versionRow = $versionStmt->fetch(PDO::FETCH_ASSOC);
                if ($versionRow === false) {
                    throw new NotFoundException('User not found.');
                }
                $newAuthVersion = AuthVersion::fromDatabase($versionRow['auth_version']);
                $next['auth_version'] = $newAuthVersion;

                // 5. Audit in same transaction.
                $this->audit->record(
                    new RbacAuditPayload(
                        action: $action,
                        entityType: 'user_role',
                        entityId: (string) $next['user_role_id'],
                        previous: $previous,
                        next: $next,
                        reason: $reason,
                    ),
                    actorType: $actorUserId !== null ? 'user' : 'system',
                    actorUserId: $actorUserId,
                    source: 'rbac',
                );
            });
        } catch (NotFoundException | ConflictException | AuthVersionCeilingException | ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof PDOException && $this->isDuplicateKey($exception)) {
                throw new ConflictException('Role is already assigned.');
            }
            throw $exception;
        }

        // 6. Post-commit physical session revocation (best-effort; auth_version is backstop).
        $this->sessions->revokeAllForUser($targetUserId);
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
