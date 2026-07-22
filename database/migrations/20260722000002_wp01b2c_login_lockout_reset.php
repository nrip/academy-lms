<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-01B-2c: login lockout window fields + password-reset authorization table.
 */
final class Wp01b2cLoginLockoutReset extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE users
    ADD COLUMN failed_login_window_started_at DATETIME(6) NULL AFTER failed_login_count,
    ADD COLUMN last_failed_login_at DATETIME(6) NULL AFTER locked_until,
    ADD COLUMN last_login_at DATETIME(6) NULL AFTER last_failed_login_at
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE password_reset_authorizations (
    password_reset_authorization_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    verification_token_id BIGINT UNSIGNED NOT NULL,
    authorization_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (password_reset_authorization_id),
    UNIQUE KEY uq_pra_authorization_hash (authorization_hash),
    KEY idx_pra_user_id (user_id),
    KEY idx_pra_expires_at (expires_at),
    CONSTRAINT fk_pra_user_id FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pra_verification_token_id FOREIGN KEY (verification_token_id) REFERENCES verification_tokens (verification_token_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS password_reset_authorizations');
        $this->execute(<<<'SQL'
ALTER TABLE users
    DROP COLUMN last_login_at,
    DROP COLUMN last_failed_login_at,
    DROP COLUMN failed_login_window_started_at
SQL);
    }
}
