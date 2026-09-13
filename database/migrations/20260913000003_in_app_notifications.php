<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Learner in-app inbox. One row per delivered transactional outbox message.
 * Body is the rendered learner letter. No recipient address, token, or object key.
 */
final class InAppNotifications extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE in_app_notifications (
    in_app_notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    outbox_message_id BIGINT UNSIGNED NOT NULL,
    source_event_type VARCHAR(128) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    href VARCHAR(255) NOT NULL,
    read_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (in_app_notification_id),
    UNIQUE KEY uq_in_app_notifications_outbox (outbox_message_id),
    KEY idx_in_app_notifications_user_created (user_id, created_at),
    CONSTRAINT fk_in_app_notifications_outbox FOREIGN KEY (outbox_message_id)
        REFERENCES outbox_messages (outbox_message_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_in_app_notifications_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS in_app_notifications');
    }
}
