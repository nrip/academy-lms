<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationWp02Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testMigrateCreatesAllWp02Tables(): void
    {
        $pdo = DatabaseTestCase::pdo();

        foreach ([
            'courses',
            'course_versions',
            'eligibility_rules',
            'course_document_requirements',
            'batches',
            'applications',
        ] as $table) {
            self::assertTrue($this->tableExists($pdo, $table), $table . ' must exist.');
        }
    }

    public function testTriggerRejectsConfigUpdateOnLockedCourseVersion(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $pdo = DatabaseTestCase::pdo();

        $this->expectException(PDOException::class);
        $stmt = $pdo->prepare('UPDATE course_versions SET title = :title WHERE version_id = :id');
        $stmt->execute(['title' => 'Hacked title', 'id' => $seeded['version_id']]);
    }

    public function testTriggerRejectsDeleteOfLockedCourseVersion(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse(['set_current_published_version' => false]);
        $pdo = DatabaseTestCase::pdo();

        $this->expectException(PDOException::class);
        $stmt = $pdo->prepare('DELETE FROM course_versions WHERE version_id = :id');
        $stmt->execute(['id' => $seeded['version_id']]);
    }

    public function testTriggerAllowsStatusOnlyTransitionOnLockedVersion(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $pdo = DatabaseTestCase::pdo();

        $stmt = $pdo->prepare("UPDATE course_versions SET status = 'archived' WHERE version_id = :id");
        $stmt->execute(['id' => $seeded['version_id']]);

        $check = $pdo->prepare('SELECT status FROM course_versions WHERE version_id = :id');
        $check->execute(['id' => $seeded['version_id']]);
        self::assertSame('archived', $check->fetchColumn());
    }

    public function testTriggerRejectsInsertingEligibilityRuleOnLockedVersion(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $this->expectException(PDOException::class);
        $stmt = $pdo->prepare(
            'INSERT INTO eligibility_rules (
                course_version_id, field, operator, value, logic_group, display_label, sort_order, created_at, updated_at
            ) VALUES (:version_id, :field, :operator, :value, :logic_group, :label, 1, :created_at, :updated_at)',
        );
        $stmt->execute([
            'version_id' => $seeded['version_id'],
            'field' => 'profession',
            'operator' => 'in',
            'value' => 'doctor',
            'logic_group' => 'AND',
            'label' => 'Must be a doctor.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function testRollbackThenReapply(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'applications'));

        $this->runPhinxRollback('20260722000003');

        self::assertFalse($this->tableExists($pdo, 'applications'));
        self::assertFalse($this->tableExists($pdo, 'batches'));
        self::assertFalse($this->tableExists($pdo, 'courses'));

        // Prior foundation tables remain untouched.
        self::assertTrue($this->tableExists($pdo, 'users'));
        self::assertTrue($this->tableExists($pdo, 'learner_profiles'));

        $this->runPhinxMigrate();
        self::assertTrue($this->tableExists($pdo, 'applications'));
        self::assertTrue($this->tableExists($pdo, 'courses'));
    }

    private function runPhinxMigrate(): void
    {
        $this->runPhinx(['migrate', '-e', 'testing']);
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
