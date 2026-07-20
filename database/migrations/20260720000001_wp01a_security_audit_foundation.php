<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Wp01aSecurityAuditFoundation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE sessions (
    session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_token_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    payload JSON NOT NULL,
    csrf_token_hash CHAR(64) NULL,
    ip_address VARBINARY(16) NULL,
    user_agent_hash CHAR(64) NULL,
    created_at DATETIME(6) NOT NULL,
    last_activity_at DATETIME(6) NOT NULL,
    absolute_expires_at DATETIME(6) NOT NULL,
    idle_expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (session_id),
    UNIQUE KEY uq_sessions_session_token_hash (session_token_hash),
    KEY idx_sessions_user_id (user_id),
    KEY idx_sessions_idle_expires_at (idle_expires_at),
    KEY idx_sessions_absolute_expires_at (absolute_expires_at),
    KEY idx_sessions_revoked_at (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE rate_limit_buckets (
    rate_limit_bucket_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket_key VARCHAR(128) NOT NULL,
    policy_key VARCHAR(64) NOT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 0,
    window_starts_at DATETIME(6) NOT NULL,
    window_ends_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (rate_limit_bucket_id),
    UNIQUE KEY uq_rate_limit_buckets_bucket_key (bucket_key),
    KEY idx_rate_limit_buckets_window_ends_at (window_ends_at),
    KEY idx_rate_limit_buckets_policy_key (policy_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE audit_log (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_type VARCHAR(32) NOT NULL,
    action VARCHAR(128) NOT NULL,
    affected_entity_type VARCHAR(64) NOT NULL,
    affected_entity_id VARCHAR(64) NOT NULL,
    previous_value JSON NULL,
    new_value JSON NULL,
    reason VARCHAR(512) NULL,
    source VARCHAR(64) NOT NULL,
    correlation_id CHAR(36) NULL,
    ip_address VARBINARY(16) NULL,
    user_agent_hash CHAR(64) NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (audit_id),
    KEY idx_audit_log_occurred_at (occurred_at),
    KEY idx_audit_log_actor_user_id (actor_user_id),
    KEY idx_audit_log_entity (affected_entity_type, affected_entity_id),
    KEY idx_audit_log_action (action),
    KEY idx_audit_log_correlation_id (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_audit_log_forbid_update
BEFORE UPDATE ON audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_audit_log_forbid_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE outbox_messages (
    outbox_message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(128) NOT NULL,
    aggregate_type VARCHAR(64) NOT NULL,
    aggregate_id VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(128) NULL,
    lock_expires_at DATETIME(6) NULL,
    published_at DATETIME(6) NULL,
    dead_at DATETIME(6) NULL,
    last_error VARCHAR(1024) NULL,
    correlation_id CHAR(36) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (outbox_message_id),
    UNIQUE KEY uq_outbox_messages_idempotency_key (idempotency_key),
    KEY idx_outbox_messages_claim (status, available_at, lock_expires_at),
    KEY idx_outbox_messages_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE scheduler_locks (
    lock_name VARCHAR(64) NOT NULL,
    locked_until DATETIME(6) NOT NULL,
    locked_by VARCHAR(128) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (lock_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_audit_log_forbid_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_audit_log_forbid_delete');
        $this->table('scheduler_locks')->drop()->save();
        $this->table('outbox_messages')->drop()->save();
        $this->table('audit_log')->drop()->save();
        $this->table('rate_limit_buckets')->drop()->save();
        $this->table('sessions')->drop()->save();
    }
}
