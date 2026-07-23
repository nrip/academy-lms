<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-06 — Webhook receipts, Enrolments, payment.enrolment_id, Finance reconcile perms.
 */
final class Wp06WebhookAdmissionEnrolment extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE payment_webhook_events (
    webhook_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(32) NOT NULL,
    provider_event_id VARCHAR(128) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    provider_order_id VARCHAR(128) NULL,
    provider_payment_id VARCHAR(128) NULL,
    payload_digest CHAR(64) NOT NULL,
    amount_minor INT UNSIGNED NULL,
    currency CHAR(3) NULL,
    provider_status VARCHAR(64) NULL,
    captured_flag TINYINT UNSIGNED NULL,
    failure_code VARCHAR(64) NULL,
    failure_category VARCHAR(64) NULL,
    occurred_at DATETIME(6) NULL,
    signature_verified_at DATETIME(6) NOT NULL,
    received_at DATETIME(6) NOT NULL,
    processing_status VARCHAR(32) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    failure_category_processing VARCHAR(64) NULL,
    lease_owner VARCHAR(128) NULL,
    lease_token CHAR(36) NULL,
    lease_expires_at DATETIME(6) NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    processed_at DATETIME(6) NULL,
    ignore_reason VARCHAR(128) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (webhook_event_id),
    UNIQUE KEY uq_payment_webhook_provider_event (provider, provider_event_id),
    KEY idx_payment_webhook_process (processing_status, next_attempt_at, lease_expires_at),
    KEY idx_payment_webhook_order (provider, provider_order_id),
    KEY idx_payment_webhook_payment_ref (provider, provider_payment_id),
    CONSTRAINT chk_payment_webhook_provider CHECK (provider IN ('razorpay')),
    CONSTRAINT chk_payment_webhook_status CHECK (processing_status IN (
        'received', 'processing', 'processed', 'ignored', 'failed', 'dead'
    )),
    CONSTRAINT chk_payment_webhook_captured CHECK (
        captured_flag IS NULL OR captured_flag IN (0, 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE enrolments (
    enrolment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_reference VARCHAR(64) NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    course_version_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    lifecycle_status VARCHAR(32) NOT NULL,
    academic_status VARCHAR(32) NULL,
    admitted_at DATETIME(6) NOT NULL,
    activated_at DATETIME(6) NULL,
    access_expires_at DATETIME(6) NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (enrolment_id),
    UNIQUE KEY uq_enrolments_public_reference (public_reference),
    UNIQUE KEY uq_enrolments_application (application_id),
    UNIQUE KEY uq_enrolments_payment (payment_id),
    KEY idx_enrolments_user (user_id),
    KEY idx_enrolments_batch_lifecycle (batch_id, lifecycle_status),
    KEY idx_enrolments_course_version (course_version_id),
    CONSTRAINT fk_enrolments_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enrolments_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enrolments_course FOREIGN KEY (course_id)
        REFERENCES courses (course_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enrolments_course_version FOREIGN KEY (course_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enrolments_batch FOREIGN KEY (batch_id)
        REFERENCES batches (batch_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enrolments_payment FOREIGN KEY (payment_id)
        REFERENCES payments (payment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_enrolments_lifecycle CHECK (lifecycle_status IN (
        'scheduled', 'active', 'suspended', 'withdrawn', 'cancelled', 'refunded', 'access_expired'
    )),
    CONSTRAINT chk_enrolments_academic CHECK (
        academic_status IS NULL OR academic_status IN (
            'not_started', 'in_progress', 'academically_completed', 'passed', 'not_passed'
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE enrolment_status_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    lifecycle_before VARCHAR(32) NOT NULL,
    lifecycle_after VARCHAR(32) NOT NULL,
    source VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (history_id),
    KEY idx_enrolment_status_history_enrolment (enrolment_id, created_at),
    CONSTRAINT fk_enrolment_status_history_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enrolment_status_history_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enrolment_status_history_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_enrolment_status_history_forbid_update
BEFORE UPDATE ON enrolment_status_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'enrolment_status_history is append-only';
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_enrolment_status_history_forbid_delete
BEFORE DELETE ON enrolment_status_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'enrolment_status_history is append-only';
END
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE payments
    ADD COLUMN enrolment_id BIGINT UNSIGNED NULL AFTER application_id,
    ADD UNIQUE KEY uq_payments_enrolment (enrolment_id),
    ADD CONSTRAINT fk_payments_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE payments
    ADD COLUMN reconcile_lease_owner VARCHAR(128) NULL AFTER reconciled_at,
    ADD COLUMN reconcile_lease_token CHAR(36) NULL AFTER reconcile_lease_owner,
    ADD COLUMN reconcile_lease_expires_at DATETIME(6) NULL AFTER reconcile_lease_token,
    ADD INDEX idx_payments_reconcile_lease (status, reconcile_lease_expires_at)
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $permissions = [
            ['finance.payment.reconcile', 'View payment reconciliation queue', 1],
            ['finance.payment.retry_reconciliation', 'Retry payment reconciliation', 1],
            ['enrolment.view_own', 'View own enrolment confirmation', 0],
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

        foreach (['finance_admin', 'super_admin'] as $roleKey) {
            foreach (['finance.payment.reconcile', 'finance.payment.retry_reconciliation'] as $key) {
                $this->execute(sprintf(
                    "INSERT INTO role_permissions (role_id, permission_id, created_at)
                     SELECT r.role_id, p.permission_id, %s
                     FROM roles r
                     CROSS JOIN permissions p
                     WHERE r.role_key = %s AND p.permission_key = %s",
                    $this->quote($now),
                    $this->quote($roleKey),
                    $this->quote($key),
                ));
            }
        }

        foreach (['applicant', 'super_admin'] as $roleKey) {
            $this->execute(sprintf(
                "INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 CROSS JOIN permissions p
                 WHERE r.role_key = %s AND p.permission_key = 'enrolment.view_own'",
                $this->quote($now),
                $this->quote($roleKey),
            ));
        }
    }

    public function down(): void
    {
        $keys = [
            'finance.payment.reconcile',
            'finance.payment.retry_reconciliation',
            'enrolment.view_own',
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

        $this->execute('ALTER TABLE payments DROP FOREIGN KEY fk_payments_enrolment');
        $this->execute('ALTER TABLE payments DROP INDEX uq_payments_enrolment');
        $this->execute('ALTER TABLE payments DROP COLUMN enrolment_id');
        $this->execute('ALTER TABLE payments DROP INDEX idx_payments_reconcile_lease');
        $this->execute('ALTER TABLE payments DROP COLUMN reconcile_lease_owner');
        $this->execute('ALTER TABLE payments DROP COLUMN reconcile_lease_token');
        $this->execute('ALTER TABLE payments DROP COLUMN reconcile_lease_expires_at');

        $this->execute('DROP TRIGGER IF EXISTS trg_enrolment_status_history_forbid_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_enrolment_status_history_forbid_delete');
        $this->execute('DROP TABLE IF EXISTS enrolment_status_history');
        $this->execute('DROP TABLE IF EXISTS enrolments');
        $this->execute('DROP TABLE IF EXISTS payment_webhook_events');
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
