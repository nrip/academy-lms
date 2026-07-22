<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationWp04Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testMigrateCreatesWp04Tables(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach ([
            'reviewer_scope_assignments',
            'application_review_assignments',
            'verification_audit_log',
        ] as $table) {
            self::assertTrue($this->tableExists($pdo, $table), $table . ' must exist.');
        }
    }

    public function testDocumentSubmissionsHaveReviewColumns(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach (['learner_visible_message', 'reviewed_by_user_id', 'reviewed_at'] as $column) {
            self::assertTrue(
                $this->columnExists($pdo, 'document_submissions', $column),
                'document_submissions.' . $column . ' must exist.',
            );
        }
    }

    public function testVerificationAuditLogIsAppendOnly(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $pdo = DatabaseTestCase::pdo();

        $insert = $pdo->prepare(
            'INSERT INTO verification_audit_log (
                application_id, reviewer_user_id, action, created_at
            ) VALUES (?, ?, ?, UTC_TIMESTAMP(6))',
        );
        $insert->execute([$fixture['application_id'], $fixture['reviewer_user_id'], 'test_append']);

        $auditId = (int) $pdo->lastInsertId();

        $this->expectException(PDOException::class);
        $pdo->prepare('UPDATE verification_audit_log SET action = ? WHERE verification_audit_id = ?')
            ->execute(['tampered', $auditId]);
    }

    public function testWp03ApplicationSurvivesAfterMigrate(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $pdo = DatabaseTestCase::pdo();

        $stmt = $pdo->prepare('SELECT status, state_version FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($row);
        self::assertSame('under_review', $row['status']);
        self::assertSame($fixture['state_version'], (int) $row['state_version']);
    }

    public function testWp04PermissionsSeeded(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach ([
            'reviewer.application.claim',
            'reviewer.application.approve',
            'reviewer.application.reject',
            'application.resubmit_corrections_own',
        ] as $permissionKey) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE permission_key = ?');
            $stmt->execute([$permissionKey]);
            self::assertSame(1, (int) $stmt->fetchColumn(), $permissionKey . ' must exist.');
        }

        $reviewerRoleId = DatabaseTestCase::roleId('credential_reviewer');
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM role_permissions rp
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE rp.role_id = ? AND p.permission_key = ?',
        );
        $stmt->execute([$reviewerRoleId, 'reviewer.application.claim']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRollbackThenReapply(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'verification_audit_log'));

        $this->runPhinxRollback('20260722000005');

        self::assertFalse($this->tableExists($pdo, 'verification_audit_log'));
        self::assertFalse($this->tableExists($pdo, 'application_review_assignments'));
        self::assertFalse($this->tableExists($pdo, 'reviewer_scope_assignments'));
        self::assertFalse($this->columnExists($pdo, 'document_submissions', 'reviewed_at'));
        self::assertTrue($this->tableExists($pdo, 'document_submissions'), 'WP-03 tables must remain.');

        DatabaseTestCase::migrate();
        self::assertTrue($this->tableExists($pdo, 'verification_audit_log'));
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

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
