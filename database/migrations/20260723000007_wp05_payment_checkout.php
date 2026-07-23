<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-05 — Payment Checkout: payments + status history, learner/finance permissions.
 * Webhook events / Successful acceptance / Admitted are WP-06.
 */
final class Wp05PaymentCheckout extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_reference VARCHAR(64) NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    provider_order_id VARCHAR(128) NULL,
    provider_payment_id VARCHAR(128) NULL,
    base_fee_minor INT UNSIGNED NOT NULL,
    gst_minor INT UNSIGNED NOT NULL,
    amount_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    gst_rate_percent DECIMAL(5,2) NOT NULL,
    course_version_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    fee_override_applied DECIMAL(12,2) NULL,
    status VARCHAR(32) NOT NULL,
    failure_code VARCHAR(64) NULL,
    failure_category VARCHAR(64) NULL,
    attempt_number INT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    successful_marker TINYINT UNSIGNED NULL,
    initiated_at DATETIME(6) NOT NULL,
    provider_order_bound_at DATETIME(6) NULL,
    authorized_at DATETIME(6) NULL,
    captured_at DATETIME(6) NULL,
    failed_at DATETIME(6) NULL,
    expired_at DATETIME(6) NULL,
    reconciled_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payments_public_reference (public_reference),
    UNIQUE KEY uq_payments_idempotency (idempotency_key),
    UNIQUE KEY uq_payments_provider_order (provider, provider_order_id),
    UNIQUE KEY uq_payments_application_attempt (application_id, attempt_number),
    UNIQUE KEY uq_payments_successful_marker (application_id, successful_marker),
    KEY idx_payments_application_status (application_id, status),
    KEY idx_payments_user (user_id),
    KEY idx_payments_status_created (status, created_at),
    CONSTRAINT fk_payments_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_payments_course_version FOREIGN KEY (course_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_payments_batch FOREIGN KEY (batch_id)
        REFERENCES batches (batch_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_payments_provider CHECK (provider IN ('razorpay')),
    CONSTRAINT chk_payments_status CHECK (status IN (
        'created', 'pending', 'successful', 'failed', 'cancelled', 'expired',
        'reconciliation_pending', 'refunded', 'partially_refunded', 'disputed'
    )),
    CONSTRAINT chk_payments_amount CHECK (
        amount_minor = base_fee_minor + gst_minor AND amount_minor >= 1
    ),
    CONSTRAINT chk_payments_successful_marker CHECK (
        successful_marker IS NULL OR successful_marker = 1
    ),
    CONSTRAINT chk_payments_attempt CHECK (attempt_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE payment_status_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    status_before VARCHAR(32) NOT NULL,
    status_after VARCHAR(32) NOT NULL,
    source VARCHAR(64) NOT NULL,
    provider_event_reference VARCHAR(128) NULL,
    reason VARCHAR(255) NULL,
    failure_category VARCHAR(64) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (history_id),
    KEY idx_payment_status_history_payment (payment_id, created_at),
    KEY idx_payment_status_history_application (application_id, created_at),
    CONSTRAINT fk_payment_status_history_payment FOREIGN KEY (payment_id)
        REFERENCES payments (payment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_payment_status_history_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_payment_status_history_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_payment_status_history_forbid_update
BEFORE UPDATE ON payment_status_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_status_history is append-only';
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_payment_status_history_forbid_delete
BEFORE DELETE ON payment_status_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_status_history is append-only';
END
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $permissions = [
            ['payment.initiate_own', 'Initiate own application payment', 0],
            ['payment.view_own', 'View own application payments', 0],
            ['payment.retry_own', 'Retry own failed/cancelled/expired payment', 0],
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
            foreach (['payment.initiate_own', 'payment.view_own', 'payment.retry_own'] as $key) {
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
    }

    public function down(): void
    {
        $keys = [
            'payment.initiate_own',
            'payment.view_own',
            'payment.retry_own',
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

        $this->execute('DROP TRIGGER IF EXISTS trg_payment_status_history_forbid_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_payment_status_history_forbid_delete');
        $this->execute('DROP TABLE IF EXISTS payment_status_history');
        $this->execute('DROP TABLE IF EXISTS payments');
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
