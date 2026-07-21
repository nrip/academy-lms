<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-01B-1 — Identity and RBAC Foundation.
 *
 * auth_version is BIGINT UNSIGNED in MySQL. Application code treats the
 * operational ceiling as PHP_INT_MAX (9223372036854775807) so values never
 * coerce through float or exceed signed 64-bit integers in PHP.
 */
final class Wp01b1IdentityRbacFoundation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    email_verified_at DATETIME(6) NULL,
    mobile_e164 VARCHAR(20) NOT NULL,
    mobile_verified_at DATETIME(6) NULL,
    password_hash VARCHAR(255) NOT NULL,
    account_status VARCHAR(32) NOT NULL,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(6) NULL,
    auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at DATETIME(6) NOT NULL,
    terms_accepted_at DATETIME(6) NOT NULL,
    terms_version VARCHAR(64) NOT NULL,
    privacy_accepted_at DATETIME(6) NOT NULL,
    privacy_version VARCHAR(64) NOT NULL,
    email_suppressed_at DATETIME(6) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_mobile_e164 (mobile_e164),
    KEY idx_users_account_status (account_status),
    KEY idx_users_locked_until (locked_until),
    KEY idx_users_auth_version (auth_version),
    CONSTRAINT chk_users_account_status CHECK (
        account_status IN ('pending_verification', 'active', 'suspended')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE roles (
    role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    is_privileged TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_role_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE permissions (
    permission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(128) NOT NULL,
    description VARCHAR(255) NOT NULL,
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (permission_id),
    UNIQUE KEY uq_permissions_permission_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission_id (permission_id),
    CONSTRAINT fk_role_permissions_role_id FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_role_permissions_permission_id FOREIGN KEY (permission_id) REFERENCES permissions (permission_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE user_roles (
    user_role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revocation_reason VARCHAR(512) NULL,
    current_marker TINYINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_role_id),
    UNIQUE KEY uq_user_roles_current (user_id, role_id, current_marker),
    KEY idx_user_roles_user_current (user_id, current_marker),
    KEY idx_user_roles_role_id (role_id),
    CONSTRAINT chk_user_roles_current_marker CHECK (current_marker IS NULL OR current_marker = 1),
    CONSTRAINT fk_user_roles_user_id FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_user_roles_role_id FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_user_roles_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_user_roles_revoked_by FOREIGN KEY (revoked_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE sessions
    ADD COLUMN auth_version BIGINT UNSIGNED NULL AFTER user_id,
    ADD CONSTRAINT fk_sessions_user_id FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL);

        $this->seedRolesAndPermissions();
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE sessions DROP FOREIGN KEY fk_sessions_user_id');
        $this->execute('ALTER TABLE sessions DROP COLUMN auth_version');
        $this->execute('DROP TABLE IF EXISTS user_roles');
        $this->execute('DROP TABLE IF EXISTS role_permissions');
        $this->execute('DROP TABLE IF EXISTS permissions');
        $this->execute('DROP TABLE IF EXISTS roles');
        $this->execute('DROP TABLE IF EXISTS users');
    }

    private function seedRolesAndPermissions(): void
    {
        $now = gmdate('Y-m-d H:i:s.000000');

        $roles = [
            ['applicant', 'Applicant / Learner', 0],
            ['credential_reviewer', 'Credential Reviewer', 1],
            ['finance_admin', 'Finance Administrator', 1],
            ['super_admin', 'Super Administrator', 1],
        ];
        foreach ($roles as [$key, $name, $privileged]) {
            $this->execute(sprintf(
                "INSERT INTO roles (role_key, name, is_privileged, created_at, updated_at)
                 VALUES (%s, %s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), is_privileged = VALUES(is_privileged), updated_at = VALUES(updated_at)",
                $this->quote($key),
                $this->quote($name),
                $privileged,
                $this->quote($now),
                $this->quote($now),
            ));
        }

        $permissions = $this->permissionCatalogue();
        foreach ($permissions as [$key, $description, $sensitive]) {
            $this->execute(sprintf(
                "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
                 VALUES (%s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
                $this->quote($key),
                $this->quote($description),
                $sensitive,
                $this->quote($now),
            ));
        }

        $matrix = $this->rolePermissionMatrix();
        foreach ($matrix as $roleKey => $permissionKeys) {
            foreach ($permissionKeys as $permissionKey) {
                $this->execute(sprintf(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                     SELECT r.role_id, p.permission_id, %s
                     FROM roles r
                     INNER JOIN permissions p ON p.permission_key = %s
                     WHERE r.role_key = %s",
                    $this->quote($now),
                    $this->quote($permissionKey),
                    $this->quote($roleKey),
                ));
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function permissionCatalogue(): array
    {
        return [
            ['identity.session.view_own', 'View own sessions', 0],
            ['identity.session.revoke_own', 'Revoke own sessions', 0],
            ['identity.password.change_own', 'Change own password', 0],
            ['profile.personal.view_own', 'View own personal profile', 0],
            ['profile.personal.edit_own', 'Edit own personal profile', 0],
            ['profile.professional.view_own', 'View own professional profile', 0],
            ['profile.professional.edit_own', 'Edit own professional profile', 0],
            ['profile.view_any', 'View any profile', 1],
            ['profile.edit_any', 'Edit any profile', 1],
            ['mfa.totp.enrol', 'Enrol TOTP MFA', 1],
            ['mfa.totp.verify', 'Verify TOTP MFA', 1],
            ['mfa.recovery.use', 'Use MFA recovery code', 1],
            ['mfa.reset', 'Reset MFA for a user', 1],
            ['rbac.role.view', 'View roles', 1],
            ['rbac.role.assign', 'Assign roles', 1],
            ['rbac.role.revoke', 'Revoke roles', 1],
            ['rbac.permission.view', 'View permissions', 1],
            ['reviewer.assignment.create', 'Create reviewer assignment', 1],
            ['reviewer.assignment.revoke', 'Revoke reviewer assignment', 1],
            ['reviewer.assignment.view', 'View reviewer assignments', 1],
            ['reviewer.assignment.view_own', 'View own reviewer assignments', 1],
            ['reviewer.queue.view', 'View reviewer queue', 1],
            ['reviewer.application.view', 'View applications as reviewer', 1],
            ['reviewer.document.review', 'Review credential documents', 1],
            ['reviewer.document.history', 'View document review history', 1],
            ['document.metadata.view', 'View document metadata', 1],
            ['document.signed_url.generate', 'Generate signed document URL', 1],
            ['finance.dashboard.view', 'View finance dashboard', 1],
            ['finance.payment.view', 'View payments', 1],
            ['finance.refund.approve', 'Approve refunds', 1],
            ['account.suspend', 'Suspend account', 1],
            ['account.activate', 'Activate account', 1],
            ['account.unlock', 'Unlock account', 1],
            ['audit.view', 'View audit log', 1],
            ['application.create', 'Create application', 0],
            ['application.view_own', 'View own applications', 0],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rolePermissionMatrix(): array
    {
        $identity = [
            'identity.session.view_own',
            'identity.session.revoke_own',
            'identity.password.change_own',
        ];
        $profileOwn = [
            'profile.personal.view_own',
            'profile.personal.edit_own',
            'profile.professional.view_own',
            'profile.professional.edit_own',
        ];
        $mfa = ['mfa.totp.enrol', 'mfa.totp.verify', 'mfa.recovery.use'];

        return [
            'applicant' => array_merge($identity, $profileOwn, [
                'application.create',
                'application.view_own',
            ]),
            'credential_reviewer' => array_merge($identity, $profileOwn, $mfa, [
                'reviewer.assignment.view_own',
                'reviewer.queue.view',
                'reviewer.application.view',
                'reviewer.document.review',
                'reviewer.document.history',
                'document.metadata.view',
                'document.signed_url.generate',
            ]),
            'finance_admin' => array_merge($identity, $profileOwn, $mfa, [
                'reviewer.assignment.view_own',
                'finance.dashboard.view',
                'finance.payment.view',
                'finance.refund.approve',
            ]),
            'super_admin' => array_merge($identity, $profileOwn, $mfa, [
                'profile.view_any',
                'profile.edit_any',
                'mfa.reset',
                'rbac.role.view',
                'rbac.role.assign',
                'rbac.role.revoke',
                'rbac.permission.view',
                'reviewer.assignment.create',
                'reviewer.assignment.revoke',
                'reviewer.assignment.view',
                'reviewer.assignment.view_own',
                'reviewer.queue.view',
                'reviewer.application.view',
                'reviewer.document.review',
                'reviewer.document.history',
                'document.metadata.view',
                'document.signed_url.generate',
                'finance.dashboard.view',
                'finance.payment.view',
                'finance.refund.approve',
                'account.suspend',
                'account.activate',
                'account.unlock',
                'audit.view',
            ]),
        ];
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
