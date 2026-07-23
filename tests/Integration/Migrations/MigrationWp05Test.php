<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationWp05Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testMigrateCreatesPaymentsTables(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach (['payments', 'payment_status_history'] as $table) {
            self::assertTrue($this->tableExists($pdo, $table), $table . ' must exist.');
        }
    }

    public function testWp05PermissionsSeeded(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach ([
            'payment.initiate_own',
            'payment.view_own',
            'payment.retry_own',
        ] as $permissionKey) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE permission_key = ?');
            $stmt->execute([$permissionKey]);
            self::assertSame(1, (int) $stmt->fetchColumn(), $permissionKey . ' must exist.');
        }

        $applicantRoleId = DatabaseTestCase::roleId('applicant');
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM role_permissions rp
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE rp.role_id = ? AND p.permission_key = ?',
        );
        $stmt->execute([$applicantRoleId, 'payment.initiate_own']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testPaymentStatusHistoryIsAppendOnly(): void
    {
        $pdo = DatabaseTestCase::pdo();
        // Need an application + payment row for FK; use minimal seed via catalogue + synthetic user.
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $user = DatabaseTestCase::applicantFixture();
        $now = gmdate('Y-m-d H:i:s.u');

        $pdo->prepare(
            'INSERT INTO applications (
                application_number, user_id, course_version_id, batch_id, status, state_version,
                submitted_at, declaration_accepted_version, declaration_accepted_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
        )->execute([
            'APP-WP05-HIST',
            $user['user_id'],
            $seeded['version_id'],
            $seeded['batch_id'],
            'payment_pending',
            $now,
            '2026-07-22',
            $now,
            $now,
            $now,
        ]);
        $applicationId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payments (
                public_reference, application_id, user_id, provider, provider_order_id, provider_payment_id,
                base_fee_minor, gst_minor, amount_minor, currency, gst_rate_percent,
                course_version_id, batch_id, fee_override_applied, status, failure_code, failure_category,
                attempt_number, idempotency_key, row_version, successful_marker, initiated_at,
                provider_order_bound_at, authorized_at, captured_at, failed_at, expired_at, reconciled_at,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, \'razorpay\', NULL, NULL,
                10000, 1800, 11800, \'INR\', 18.00,
                ?, ?, NULL, \'created\', NULL, NULL,
                1, ?, 1, NULL, ?,
                NULL, NULL, NULL, NULL, NULL, NULL,
                ?, ?
            )',
        )->execute([
            'PAY-WP05-HIST',
            $applicationId,
            $user['user_id'],
            $seeded['version_id'],
            $seeded['batch_id'],
            'pay:wp05:hist',
            $now,
            $now,
            $now,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payment_status_history (
                payment_id, application_id, status_before, status_after, source, created_at
            ) VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([$paymentId, $applicationId, '', 'created', 'test', $now]);
        $historyId = (int) $pdo->lastInsertId();

        $this->expectException(PDOException::class);
        $pdo->prepare('UPDATE payment_status_history SET source = ? WHERE history_id = ?')
            ->execute(['tampered', $historyId]);
    }

    public function testRollbackThenReapply(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'payments'));

        $this->runPhinxRollback('20260723000006');

        self::assertFalse($this->tableExists($pdo, 'payments'));
        self::assertFalse($this->tableExists($pdo, 'payment_status_history'));
        self::assertTrue($this->tableExists($pdo, 'verification_audit_log'), 'WP-04 tables must remain.');

        DatabaseTestCase::migrate();
        self::assertTrue($this->tableExists($pdo, 'payments'));
        self::assertTrue($this->tableExists($pdo, 'payment_status_history'));
    }

    private function runPhinxRollback(string $targetVersion): void
    {
        $this->runPhinx(['rollback', '-e', 'testing', '-t', $targetVersion]);
    }

    /**
     * @param list<string> $args
     */
    private function runPhinx(array $args): void
    {
        $root = dirname(__DIR__, 3);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phinx', ...$args],
            $descriptors,
            $pipes,
            $root,
            $this->phinxEnv(),
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        self::assertSame(0, $status, trim($stdout . "\n" . $stderr));
    }

    /**
     * @return array<string, string>
     */
    private function phinxEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
        ];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
