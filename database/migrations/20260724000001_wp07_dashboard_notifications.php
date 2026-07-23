<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-07 — Learner dashboard permissions + transactional notification_deliveries.
 */
final class Wp07DashboardNotifications extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE notification_deliveries (
    notification_delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outbox_message_id BIGINT UNSIGNED NOT NULL,
    source_event_type VARCHAR(128) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(16) NOT NULL,
    template_key VARCHAR(128) NOT NULL,
    template_version INT UNSIGNED NOT NULL,
    recipient_hash CHAR(64) NOT NULL,
    recipient_masked VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    lease_owner VARCHAR(128) NULL,
    lease_token CHAR(36) NULL,
    lease_expires_at DATETIME(6) NULL,
    provider_message_id VARCHAR(128) NULL,
    failure_category VARCHAR(64) NULL,
    delivered_at DATETIME(6) NULL,
    dead_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (notification_delivery_id),
    UNIQUE KEY uq_notification_delivery_idempotency (outbox_message_id, channel, template_key),
    KEY idx_notification_deliveries_user (user_id),
    KEY idx_notification_deliveries_status_next (status, next_attempt_at, lease_expires_at),
    KEY idx_notification_deliveries_outbox (outbox_message_id),
    CONSTRAINT fk_notification_deliveries_outbox FOREIGN KEY (outbox_message_id)
        REFERENCES outbox_messages (outbox_message_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_notification_deliveries_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_notification_deliveries_channel CHECK (channel IN ('email')),
    CONSTRAINT chk_notification_deliveries_status CHECK (status IN (
        'pending', 'processing', 'delivered', 'failed', 'dead'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $permissions = [
            ['dashboard.view_own', 'View own learner dashboard', 0],
            ['notification.view', 'View notification delivery operations', 1],
            ['notification.retry', 'Retry failed/dead notification deliveries', 1],
        ];

        foreach ($permissions as [$key, $description, $sensitive]) {
            $this->execute(sprintf(
                "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
                 VALUES (%s, %s, %d, %s)",
                $this->quote($key),
                $this->quote($description),
                $sensitive,
                $this->quote($now),
            ));
        }

        foreach (['applicant', 'super_admin'] as $roleKey) {
            $this->execute(sprintf(
                "INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 CROSS JOIN permissions p
                 WHERE r.role_key = %s AND p.permission_key = 'dashboard.view_own'",
                $this->quote($now),
                $this->quote($roleKey),
            ));
        }

        foreach (['notification.view', 'notification.retry'] as $key) {
            $this->execute(sprintf(
                "INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 CROSS JOIN permissions p
                 WHERE r.role_key = 'super_admin' AND p.permission_key = %s",
                $this->quote($now),
                $this->quote($key),
            ));
        }
    }

    public function down(): void
    {
        $keys = [
            'dashboard.view_own',
            'notification.view',
            'notification.retry',
        ];
        foreach ($keys as $key) {
            $this->execute(sprintf(
                "DELETE rp FROM role_permissions rp
                 INNER JOIN permissions p ON p.permission_id = rp.permission_id
                 WHERE p.permission_key = %s",
                $this->quote($key),
            ));
            $this->execute(sprintf(
                'DELETE FROM permissions WHERE permission_key = %s',
                $this->quote($key),
            ));
        }

        $this->execute('DROP TABLE IF EXISTS notification_deliveries');
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
