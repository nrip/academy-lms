<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-01B-2b — Registration stub profile + verification permission grants.
 */
final class Wp01b2bRegistrationVerification extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE learner_profiles (
    learner_profile_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (learner_profile_id),
    UNIQUE KEY uq_learner_profiles_user (user_id),
    CONSTRAINT fk_learner_profiles_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
            ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.000000');

        $permissions = [
            ['identity.verification.view_own', 'View own verification status', 0],
            ['identity.verification.resend_own', 'Resend own verification challenges', 0],
        ];

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

        foreach (['identity.verification.view_own', 'identity.verification.resend_own'] as $permissionKey) {
            $this->execute(sprintf(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 INNER JOIN permissions p ON p.permission_key = %s
                 WHERE r.role_key = 'applicant'",
                $this->quote($now),
                $this->quote($permissionKey),
            ));
        }
    }

    public function down(): void
    {
        foreach (['identity.verification.view_own', 'identity.verification.resend_own'] as $permissionKey) {
            $this->execute(sprintf(
                "DELETE rp FROM role_permissions rp
                 INNER JOIN permissions p ON p.permission_id = rp.permission_id
                 INNER JOIN roles r ON r.role_id = rp.role_id
                 WHERE p.permission_key = %s AND r.role_key = 'applicant'",
                $this->quote($permissionKey),
            ));

            $this->execute(sprintf(
                'DELETE FROM permissions WHERE permission_key = %s',
                $this->quote($permissionKey),
            ));
        }

        $this->execute('DROP TABLE IF EXISTS learner_profiles');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
