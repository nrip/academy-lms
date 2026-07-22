<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-01B-2a — Notification crypto, verification storage, confirmation contexts.
 */
final class Wp01b2aNotificationVerificationFoundation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE verification_tokens (
    verification_token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    current_marker TINYINT UNSIGNED NULL,
    delivery_ciphertext VARBINARY(512) NULL,
    delivery_nonce BINARY(24) NULL,
    delivery_key_version SMALLINT UNSIGNED NULL,
    delivery_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    delivered_at DATETIME(6) NULL,
    provider_message_id VARCHAR(128) NULL,
    terminal_at DATETIME(6) NULL,
    delivery_last_error VARCHAR(512) NULL,
    delivery_cleared_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    last_sent_at DATETIME(6) NULL,
    PRIMARY KEY (verification_token_id),
    UNIQUE KEY uq_verification_tokens_hash (token_hash),
    UNIQUE KEY uq_verification_tokens_current (user_id, purpose, current_marker),
    KEY idx_verification_tokens_user_purpose (user_id, purpose),
    KEY idx_verification_tokens_expires (expires_at),
    KEY idx_verification_tokens_delivery_status (delivery_status),
    CONSTRAINT chk_verification_tokens_purpose
        CHECK (purpose IN ('email_verify', 'password_reset')),
    CONSTRAINT chk_verification_tokens_current_marker
        CHECK (current_marker IS NULL OR current_marker = 1),
    CONSTRAINT chk_verification_tokens_delivery_status
        CHECK (delivery_status IN ('pending', 'delivered', 'terminal')),
    CONSTRAINT chk_verification_tokens_delivery_seal_coherent
        CHECK (
            (delivery_ciphertext IS NULL AND delivery_nonce IS NULL AND delivery_key_version IS NULL)
            OR (delivery_ciphertext IS NOT NULL AND delivery_nonce IS NOT NULL AND delivery_key_version IS NOT NULL)
        ),
    CONSTRAINT chk_verification_tokens_pending_timestamps
        CHECK (
            delivery_status <> 'pending'
            OR (delivered_at IS NULL AND terminal_at IS NULL)
        ),
    CONSTRAINT chk_verification_tokens_delivered_state
        CHECK (
            delivery_status <> 'delivered'
            OR (
                delivered_at IS NOT NULL
                AND terminal_at IS NULL
                AND delivery_ciphertext IS NULL
                AND delivery_nonce IS NULL
                AND delivery_key_version IS NULL
            )
        ),
    CONSTRAINT chk_verification_tokens_terminal_state
        CHECK (
            delivery_status <> 'terminal'
            OR (
                terminal_at IS NOT NULL
                AND delivered_at IS NULL
                AND delivery_ciphertext IS NULL
                AND delivery_nonce IS NULL
                AND delivery_key_version IS NULL
            )
        ),
    CONSTRAINT chk_verification_tokens_delivery_timestamps_exclusive
        CHECK (NOT (delivered_at IS NOT NULL AND terminal_at IS NOT NULL)),
    CONSTRAINT fk_verification_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
            ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE verification_challenges (
    verification_challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(16) NOT NULL,
    destination_hmac CHAR(64) NOT NULL,
    otp_binding_nonce BINARY(16) NOT NULL,
    otp_hmac CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    consumed_at DATETIME(6) NULL,
    current_marker TINYINT UNSIGNED NULL,
    otp_delivery_ciphertext VARBINARY(256) NULL,
    otp_delivery_nonce BINARY(24) NULL,
    otp_delivery_key_version SMALLINT UNSIGNED NULL,
    delivery_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    delivered_at DATETIME(6) NULL,
    provider_message_id VARCHAR(128) NULL,
    terminal_at DATETIME(6) NULL,
    delivery_last_error VARCHAR(512) NULL,
    otp_delivery_cleared_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    last_sent_at DATETIME(6) NULL,
    PRIMARY KEY (verification_challenge_id),
    UNIQUE KEY uq_verification_challenges_current (user_id, channel, current_marker),
    KEY idx_verification_challenges_user (user_id),
    KEY idx_verification_challenges_expires (expires_at),
    KEY idx_verification_challenges_delivery_status (delivery_status),
    CONSTRAINT chk_verification_challenges_channel
        CHECK (channel IN ('sms')),
    CONSTRAINT chk_verification_challenges_current_marker
        CHECK (current_marker IS NULL OR current_marker = 1),
    CONSTRAINT chk_verification_challenges_delivery_status
        CHECK (delivery_status IN ('pending', 'delivered', 'terminal')),
    CONSTRAINT chk_verification_challenges_delivery_seal_coherent
        CHECK (
            (otp_delivery_ciphertext IS NULL AND otp_delivery_nonce IS NULL AND otp_delivery_key_version IS NULL)
            OR (otp_delivery_ciphertext IS NOT NULL AND otp_delivery_nonce IS NOT NULL AND otp_delivery_key_version IS NOT NULL)
        ),
    CONSTRAINT chk_verification_challenges_pending_timestamps
        CHECK (
            delivery_status <> 'pending'
            OR (delivered_at IS NULL AND terminal_at IS NULL)
        ),
    CONSTRAINT chk_verification_challenges_delivered_state
        CHECK (
            delivery_status <> 'delivered'
            OR (
                delivered_at IS NOT NULL
                AND terminal_at IS NULL
                AND otp_delivery_ciphertext IS NULL
                AND otp_delivery_nonce IS NULL
                AND otp_delivery_key_version IS NULL
            )
        ),
    CONSTRAINT chk_verification_challenges_terminal_state
        CHECK (
            delivery_status <> 'terminal'
            OR (
                terminal_at IS NOT NULL
                AND delivered_at IS NULL
                AND otp_delivery_ciphertext IS NULL
                AND otp_delivery_nonce IS NULL
                AND otp_delivery_key_version IS NULL
            )
        ),
    CONSTRAINT chk_verification_challenges_delivery_timestamps_exclusive
        CHECK (NOT (delivered_at IS NOT NULL AND terminal_at IS NOT NULL)),
    CONSTRAINT fk_verification_challenges_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
            ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE token_confirmation_contexts (
    token_confirmation_context_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    confirmation_hash CHAR(64) NOT NULL,
    verification_token_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (token_confirmation_context_id),
    UNIQUE KEY uq_token_confirmation_hash (confirmation_hash),
    KEY idx_token_confirmation_lookup (verification_token_id, expires_at, consumed_at),
    KEY idx_token_confirmation_cleanup (expires_at, consumed_at),
    CONSTRAINT chk_token_confirmation_purpose
        CHECK (purpose IN ('email_verify', 'password_reset')),
    CONSTRAINT fk_token_confirmation_token
        FOREIGN KEY (verification_token_id) REFERENCES verification_tokens (verification_token_id)
            ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_token_confirmation_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
            ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS token_confirmation_contexts');
        $this->execute('DROP TABLE IF EXISTS verification_challenges');
        $this->execute('DROP TABLE IF EXISTS verification_tokens');
    }
}
